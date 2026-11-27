import datetime
import unittest

from train_fire_model import (
    CATEGORICAL_FEATURES,
    NUMERIC_FEATURES,
    TARGET,
    evaluate,
    numeric_ranges,
    walk_forward_validation,
)


def sample_row(year, label, offset):
    row = {
        feature: 0.0
        for feature in NUMERIC_FEATURES
    }
    row.update({
        "month": 1 + offset % 12,
        "latitude": 12.7 + offset * 0.001,
        "longitude": 121.4 + offset * 0.001,
        "temperature_c": 25 + label * 4 + offset * 0.1,
        "relative_humidity_pct": 80 - label * 20,
        "wind_speed_kmh": 8 + label * 4,
        "rainfall_mm": 4 - label * 3,
        "days_since_last_rain": 2 + label * 5,
        "soil_moisture_pct": 65 - label * 15,
        "fuel_dryness_index": 35 + label * 20,
        "barangay": "Poblacion",
        "land_use_zone": "Urban_Residential",
        "season": "Dry_Hot_Season",
        TARGET: label,
        "_date": datetime.date(year, 1, 1 + offset),
    })
    for feature in CATEGORICAL_FEATURES:
        row.setdefault(feature, "test")
    return row


class FireRiskTrainingTests(unittest.TestCase):
    def test_numeric_ranges_capture_observed_feature_support(self):
        rows = [sample_row(2024, 0, 0), sample_row(2024, 1, 1)]

        ranges = numeric_ranges(rows)

        self.assertEqual(ranges["temperature_c"], {"minimum": 25.0, "maximum": 29.1})
        self.assertEqual(ranges["month"], {"minimum": 1, "maximum": 2})

    def test_evaluation_reports_threshold_errors_and_score_quality(self):
        metrics = evaluate(
            [([-2.0], 0), ([-1.0], 0), ([1.0], 1), ([2.0], 1)],
            intercept=0.0,
            weights=[1.0],
        )

        self.assertEqual(metrics["confusion_matrix"], {
            "true_negative": 2,
            "false_positive": 0,
            "false_negative": 0,
            "true_positive": 2,
        })
        self.assertEqual(metrics["recall"], 1.0)
        self.assertEqual(metrics["specificity"], 1.0)
        self.assertAlmostEqual(metrics["roc_auc"], 1.0)
        self.assertGreaterEqual(metrics["brier_score"], 0.0)
        self.assertGreaterEqual(metrics["log_loss"], 0.0)

    def test_walk_forward_validation_keeps_each_test_year_in_the_future(self):
        rows = [
            sample_row(year, label, label)
            for year in range(2019, 2027)
            for label in (0, 1)
        ]

        folds, summary = walk_forward_validation(rows, l2_strength=0.01)

        self.assertEqual([fold["year"] for fold in folds], [2024, 2025, 2026])
        self.assertTrue(all(fold["training_rows"] == 2 * (fold["year"] - 2019) for fold in folds))
        self.assertEqual(summary["test_rows"], 6)
        self.assertEqual(
            [item["threshold"] for item in summary["threshold_sweep"]],
            [0.25, 0.35, 0.5, 0.65],
        )


if __name__ == "__main__":
    unittest.main()
