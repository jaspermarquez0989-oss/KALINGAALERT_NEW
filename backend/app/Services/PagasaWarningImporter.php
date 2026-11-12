<?php

namespace App\Services;

use App\Enums\HazardType;
use App\Models\HazardSource;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PagasaWarningImporter
{
    private const BASE_URL = 'https://bagong.pagasa.dost.gov.ph/';

    /**
     * @var list<array{path: string, title: string}>
     */
    private const WARNING_PAGES = [
        ['path' => 'weather/weather-advisory', 'title' => 'Weather Advisory'],
        ['path' => 'marine/gale-warning', 'title' => 'Gale Warning'],
        ['path' => 'tropical-cyclone/severe-weather-bulletin', 'title' => 'Tropical Cyclone Severe Weather Bulletin'],
        ['path' => 'tropical-cyclone/severe-wind-impact-based-forecast', 'title' => 'Severe Wind Impact-Based Forecast'],
        ['path' => 'tropical-cyclone/tropical-cyclone-warning-for-shipping', 'title' => 'Tropical Cyclone Warning for Shipping'],
        ['path' => 'tropical-cyclone/forecast-storm-surge', 'title' => 'Tropical Cyclone Storm Surge Forecast'],
        ['path' => 'tropical-cyclone/tropical-cyclone-warning-for-agriculture', 'title' => 'Tropical Cyclone Warning for Agriculture'],
    ];

    public function __construct(private AuditRecorder $audit) {}

    public function sync(): int
    {
        $importedCount = 0;

        foreach (self::WARNING_PAGES as $page) {
            $url = self::BASE_URL.$page['path'];
            $response = Http::accept('text/html')
                ->withUserAgent('KalingaAlert/1.0')
                ->withOptions(['verify' => config('services.pagasa.ca_bundle') ?: true])
                ->connectTimeout(5)
                ->timeout(15)
                ->retry([250, 750], 0, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->serverError() || $exception->response->status() === 429));
                })
                ->get($url)
                ->throw();

            $content = $this->extractArticleContent($response->body(), $url);

            if ($this->isInactive($content) || ! $this->coversBongabong($content)) {
                continue;
            }

            $externalId = hash('sha256', $url."\0".$content);
            $imported = DB::transaction(function () use ($externalId, $page, $url, $content): bool {
                if (HazardSource::query()->where('external_id', $externalId)->exists()) {
                    return false;
                }

                $reference = 'PAGASA-'.substr($externalId, 0, 40);
                $source = HazardSource::create([
                    'source_type' => HazardType::Weather->value,
                    'organization' => 'PAGASA',
                    'external_id' => $externalId,
                    'reference' => $reference,
                    'title' => 'PAGASA '.$page['title'].' — Bongabong coverage',
                    'details' => $content,
                    'source_url' => $url,
                    'issued_at' => $this->issuedAt($content),
                    'verification_status' => 'pending',
                    'created_by' => null,
                ]);

                $this->audit->record(null, 'hazard_source.imported', $source, [
                    'source_type' => $source->source_type,
                    'organization' => $source->organization,
                    'reference' => $source->reference,
                    'source_url' => $source->source_url,
                ]);

                return true;
            });

            if ($imported) {
                $importedCount++;
            }
        }

        return $importedCount;
    }

    private function extractArticleContent(string $html, string $url): string
    {
        $document = new DOMDocument;
        $previousErrorSetting = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML($html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }

        if (! $loaded) {
            throw new RuntimeException("PAGASA returned an unreadable page: {$url}");
        }

        $xpath = new DOMXPath($document);
        $articles = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' article-content ')]",
        );

        if ($articles === false || $articles->length === 0) {
            throw new RuntimeException("PAGASA warning page structure changed: {$url}");
        }

        $content = [];

        foreach ($articles as $article) {
            $text = trim(preg_replace('/\s+/u', ' ', $article->textContent) ?? '');

            if ($text !== '') {
                $content[] = $text;
            }
        }

        return implode("\n\n", $content);
    }

    private function isInactive(string $content): bool
    {
        return preg_match(
            '/\bno\s+(?:active\s+)?(?:weather advisory|gale warning|tropical cyclone|severe wind|warning issued)\b/i',
            $content,
        ) === 1;
    }

    private function coversBongabong(string $content): bool
    {
        return preg_match(
            '/\b(?:Bongabong|Oriental\s+Mindoro|MIMAROPA|Region\s+(?:IV|4)\s*[- ]?\s*B)\b/i',
            $content,
        ) === 1;
    }

    private function issuedAt(string $content): CarbonImmutable
    {
        if (preg_match(
            '/\bIssued\s+at\s*:?\s*(\d{1,2}:\d{2}\s*[AP]M,\s*\d{1,2}\s+\pL+\s+\d{4})/iu',
            $content,
            $matches,
        ) === 1) {
            $issuedAt = CarbonImmutable::createFromFormat('g:i A, j F Y', $matches[1], 'Asia/Manila');

            if ($issuedAt === false) {
                throw new RuntimeException('PAGASA warning issue time could not be parsed.');
            }

            return $issuedAt->utc();
        }

        return CarbonImmutable::now();
    }
}
