import argparse
import csv
import datetime
import json
import math
from pathlib import Path


NUMERIC_FEATURES = [
    "month",
    "latitude",
    "longitude",
    "elevation_m",
    "temperature_c",
    "relative_humidity_pct",
    "wind_speed_kmh",
    "rainfall_mm",
    "days_since_last_rain",
    "soil_moisture_pct",
    "fuel_dryness_index",
    "dist_to_fire_station_km",
    "distance_to_water_km",
]
CATEGORICAL_FEATURES = ["barangay", "land_use_zone", "season"]
TARGET = "fire_occurred"
ITERATIONS = 2400
LEARNING_RATE = 0.15
L2_STRENGTH = 0.01
L2_CANDIDATES = (0.001, 0.01, 0.1, 1.0)
CONVERGENCE_TOLERANCE = 0.001


def load_rows(csv_path):
    with csv_path.open(newline="", encoding="utf-8-sig") as file:
        reader = csv.DictReader(file)
        required = set(NUMERIC_FEATURES + CATEGORICAL_FEATURES + [TARGET, "date"])
        missing = sorted(required - set(reader.fieldnames or []))
        if missing:
            raise ValueError(f"CSV is missing required columns: {', '.join(missing)}")

        rows = []
        for line_number, row in enumerate(reader, start=2):
            try:
                label = int(row[TARGET])
                if label not in (0, 1):
                    raise ValueError("target must be 0 or 1")
                row["_date"] = datetime.date.fromisoformat(row["date"].strip())
                for feature in NUMERIC_FEATURES:
                    row[feature] = float(row[feature])
                    if not math.isfinite(row[feature]):
                        raise ValueError(f"{feature} must be a finite number")
                for feature in CATEGORICAL_FEATURES:
                    row[feature] = row[feature].strip()
                    if not row[feature]:
                        raise ValueError(f"{feature} is blank")
            except (TypeError, ValueError) as error:
                raise ValueError(f"Invalid value on CSV line {line_number}: {error}") from error
            row[TARGET] = label
            rows.append(row)

    if len(rows) < 20:
        raise ValueError("At least 20 labeled rows are required.")
    if {row[TARGET] for row in rows} != {0, 1}:
        raise ValueError("The CSV must contain both fire and no-fire labels.")
    return rows


def tune_l2_strength(rows):
    years = sorted({row["_date"].year for row in rows})
    tuning_years = years[-5:-3]
    if len(tuning_years) != 2:
        raise ValueError("At least five calendar years are required for separate tuning and validation.")

    candidates = []
    for l2_strength in L2_CANDIDATES:
        fold_metrics = []
        for year in tuning_years:
            training_rows = [row for row in rows if row["_date"].year < year]
            test_rows = [row for row in rows if row["_date"].year == year]
            if not training_rows or {row[TARGET] for row in training_rows} != {0, 1}:
                raise ValueError(f"Training data before {year} must contain both labels.")
            if {row[TARGET] for row in test_rows} != {0, 1}:
                raise ValueError(f"Tuning year {year} must contain both labels.")

            means, scales, categories, feature_names = fit_schema(training_rows)
            encoded_training = [
                (encode(row, means, scales, categories), row[TARGET])
                for row in training_rows
            ]
            encoded_test = [
                (encode(row, means, scales, categories), row[TARGET])
                for row in test_rows
            ]
            intercept, weights = train(
                encoded_training,
                len(feature_names),
                l2_strength=l2_strength,
            )
            metrics = evaluate(encoded_test, intercept, weights)
            fold_metrics.append({
                "year": year,
                "test_rows": len(test_rows),
                "f1": metrics["f1"],
                "roc_auc": metrics["roc_auc"],
            })

        candidates.append({
            "l2_strength": l2_strength,
            "mean_yearly_f1": sum(fold["f1"] for fold in fold_metrics) / len(fold_metrics),
            "mean_yearly_roc_auc": sum(fold["roc_auc"] for fold in fold_metrics) / len(fold_metrics),
            "folds": fold_metrics,
        })

    selected = max(
        candidates,
        key=lambda candidate: (
            candidate["mean_yearly_f1"],
            candidate["mean_yearly_roc_auc"],
        ),
    )
    return selected["l2_strength"], {
        "years": tuning_years,
        "selection_metric": "mean_yearly_f1",
        "selected_l2_strength": selected["l2_strength"],
        "candidates": candidates,
    }


def walk_forward_validation(rows, l2_strength):
    years = sorted({row["_date"].year for row in rows})
    validation_years = years[-3:]
    if len(validation_years) < 3:
        raise ValueError("At least three calendar years are required for forward validation.")

    folds = []
    for year in validation_years:
        training_rows = [row for row in rows if row["_date"].year < year]
        test_rows = [row for row in rows if row["_date"].year == year]
        if not training_rows or not test_rows:
            raise ValueError(f"Year {year} cannot be used as a forward-validation fold.")
        if {row[TARGET] for row in training_rows} != {0, 1}:
            raise ValueError(f"Training data before {year} must contain both labels.")
        if {row[TARGET] for row in test_rows} != {0, 1}:
            raise ValueError(f"Validation year {year} must contain both labels.")

        means, scales, categories, feature_names = fit_schema(training_rows)
        encoded_training = [
            (encode(row, means, scales, categories), row[TARGET])
            for row in training_rows
        ]
        encoded_test = [
            (encode(row, means, scales, categories), row[TARGET])
            for row in test_rows
        ]
        intercept, weights = train(
            encoded_training,
            len(feature_names),
            l2_strength=l2_strength,
        )
        threshold_sweep = []
        for threshold in (0.25, 0.35, 0.5, 0.65):
            threshold_metrics = evaluate(
                encoded_test,
                intercept,
                weights,
                threshold=threshold,
            )
            threshold_sweep.append({
                "threshold": threshold,
                "precision": threshold_metrics["precision"],
                "recall": threshold_metrics["recall"],
                "false_positives": threshold_metrics["confusion_matrix"]["false_positive"],
                "false_negatives": threshold_metrics["confusion_matrix"]["false_negative"],
            })
        folds.append({
            "year": year,
            "training_rows": len(training_rows),
            "test_rows": len(test_rows),
            "test_date_range": [
                min(row["_date"] for row in test_rows).isoformat(),
                max(row["_date"] for row in test_rows).isoformat(),
            ],
            "metrics": evaluate(encoded_test, intercept, weights),
            "threshold_sweep": threshold_sweep,
        })

    confusion = {
        key: sum(fold["metrics"]["confusion_matrix"][key] for fold in folds)
        for key in ("true_negative", "false_positive", "false_negative", "true_positive")
    }
    test_count = sum(fold["test_rows"] for fold in folds)
    positives = confusion["false_negative"] + confusion["true_positive"]
    negatives = confusion["true_negative"] + confusion["false_positive"]
    precision = confusion["true_positive"] / max(1, confusion["true_positive"] + confusion["false_positive"])
    recall = confusion["true_positive"] / max(1, positives)
    specificity = confusion["true_negative"] / max(1, negatives)
    summary = {
        "years": validation_years,
        "test_rows": test_count,
        "threshold": 0.5,
        "accuracy": (confusion["true_positive"] + confusion["true_negative"]) / test_count,
        "majority_class_baseline_accuracy": max(positives, negatives) / test_count,
        "precision": precision,
        "recall": recall,
        "specificity": specificity,
        "f1": 2 * precision * recall / max(1e-12, precision + recall),
        "mean_yearly_roc_auc": sum(fold["metrics"]["roc_auc"] for fold in folds) / len(folds),
        "mean_yearly_brier_score": sum(fold["metrics"]["brier_score"] for fold in folds) / len(folds),
        "mean_yearly_log_loss": sum(fold["metrics"]["log_loss"] for fold in folds) / len(folds),
        "confusion_matrix": confusion,
        "threshold_sweep": [],
    }
    for threshold in (0.25, 0.35, 0.5, 0.65):
        counts = {
            "false_positives": sum(
                next(item for item in fold["threshold_sweep"] if item["threshold"] == threshold)["false_positives"]
                for fold in folds
            ),
            "false_negatives": sum(
                next(item for item in fold["threshold_sweep"] if item["threshold"] == threshold)["false_negatives"]
                for fold in folds
            ),
        }
        threshold_true_positives = positives - counts["false_negatives"]
        summary["threshold_sweep"].append({
            "threshold": threshold,
            "precision": threshold_true_positives / max(1, threshold_true_positives + counts["false_positives"]),
            "recall": threshold_true_positives / max(1, positives),
            **counts,
        })
    return folds, summary


def fit_schema(rows):
    means = {}
    scales = {}
    for feature in NUMERIC_FEATURES:
        values = [row[feature] for row in rows]
        mean = sum(values) / len(values)
        variance = sum((value - mean) ** 2 for value in values) / len(values)
        means[feature] = mean
        scales[feature] = math.sqrt(variance) or 1.0

    categories = {
        feature: sorted({row[feature] for row in rows})
        for feature in CATEGORICAL_FEATURES
    }
    feature_names = NUMERIC_FEATURES + [
        f"{feature}={value}"
        for feature in CATEGORICAL_FEATURES
        for value in categories[feature]
    ]
    return means, scales, categories, feature_names


def numeric_ranges(rows):
    return {
        feature: {
            "minimum": min(row[feature] for row in rows),
            "maximum": max(row[feature] for row in rows),
        }
        for feature in NUMERIC_FEATURES
    }


def encode(row, means, scales, categories):
    values = [
        (row[feature] - means[feature]) / scales[feature]
        for feature in NUMERIC_FEATURES
    ]
    for feature in CATEGORICAL_FEATURES:
        values.extend(
            1.0 if row[feature] == value else 0.0
            for value in categories[feature]
        )
    return values


def sigmoid(value):
    if value >= 0:
        exp_value = math.exp(-min(value, 700))
        return 1.0 / (1.0 + exp_value)
    exp_value = math.exp(max(value, -700))
    return exp_value / (1.0 + exp_value)


def train(rows, feature_count, l2_strength=L2_STRENGTH):
    weights = [0.0] * feature_count
    intercept = 0.0
    for _ in range(ITERATIONS):
        gradients = [0.0] * feature_count
        intercept_gradient = 0.0
        for values, label in rows:
            probability = sigmoid(intercept + sum(
                weight * value for weight, value in zip(weights, values)
            ))
            error = probability - label
            intercept_gradient += error
            for index, value in enumerate(values):
                gradients[index] += error * value

        count = len(rows)
        intercept -= LEARNING_RATE * intercept_gradient / count
        for index in range(feature_count):
            gradient = gradients[index] / count + l2_strength * weights[index]
            weights[index] -= LEARNING_RATE * gradient
    return intercept, weights


def optimization_diagnostics(rows, intercept, weights, l2_strength):
    intercept_gradient = 0.0
    gradients = [0.0] * len(weights)
    objective = 0.0
    for values, label in rows:
        score = intercept + sum(weight * value for weight, value in zip(weights, values))
        probability = sigmoid(score)
        error = probability - label
        intercept_gradient += error
        objective += -(
            label * math.log(max(probability, 1e-15))
            + (1 - label) * math.log(max(1 - probability, 1e-15))
        )
        for index, value in enumerate(values):
            gradients[index] += error * value

    count = len(rows)
    objective = objective / count + l2_strength * sum(weight ** 2 for weight in weights) / 2
    gradients = [
        gradient / count + l2_strength * weight
        for gradient, weight in zip(gradients, weights)
    ]
    intercept_gradient /= count
    maximum_gradient = max([abs(intercept_gradient), *(abs(gradient) for gradient in gradients)])

    return {
        "objective": objective,
        "max_absolute_gradient": maximum_gradient,
        "gradient_tolerance": CONVERGENCE_TOLERANCE,
        "converged": maximum_gradient <= CONVERGENCE_TOLERANCE,
    }


def evaluate(rows, intercept, weights, threshold=0.5):
    predictions = []
    for values, label in rows:
        probability = sigmoid(intercept + sum(
            weight * value for weight, value in zip(weights, values)
        ))
        predictions.append((probability, label))

    tp = sum(probability >= threshold and label == 1 for probability, label in predictions)
    tn = sum(probability < threshold and label == 0 for probability, label in predictions)
    fp = sum(probability >= threshold and label == 0 for probability, label in predictions)
    fn = sum(probability < threshold and label == 1 for probability, label in predictions)
    precision = tp / (tp + fp) if tp + fp else 0.0
    recall = tp / (tp + fn) if tp + fn else 0.0
    f1 = 2 * precision * recall / (precision + recall) if precision + recall else 0.0
    specificity = tn / (tn + fp) if tn + fp else 0.0

    positives = sum(label == 1 for _, label in predictions)
    negatives = len(predictions) - positives
    ranked = sorted(predictions, key=lambda item: item[0])
    rank_sum = 0.0
    index = 0
    while index < len(ranked):
        end = index + 1
        while end < len(ranked) and ranked[end][0] == ranked[index][0]:
            end += 1
        average_rank = ((index + 1) + end) / 2
        rank_sum += average_rank * sum(
            ranked[position][1] == 1 for position in range(index, end)
        )
        index = end
    auc = (rank_sum - positives * (positives + 1) / 2) / (positives * negatives)
    majority_accuracy = max(positives, negatives) / len(predictions)
    brier_score = sum((probability - label) ** 2 for probability, label in predictions) / len(predictions)
    log_loss = -sum(
        label * math.log(max(probability, 1e-15))
        + (1 - label) * math.log(max(1 - probability, 1e-15))
        for probability, label in predictions
    ) / len(predictions)
    return {
        "test_rows": len(predictions),
        "positive_test_rows": positives,
        "threshold": threshold,
        "accuracy": (tp + tn) / len(predictions),
        "majority_class_baseline_accuracy": majority_accuracy,
        "precision": precision,
        "recall": recall,
        "specificity": specificity,
        "f1": f1,
        "roc_auc": auc,
        "brier_score": brier_score,
        "log_loss": log_loss,
        "confusion_matrix": {"true_negative": tn, "false_positive": fp,
                             "false_negative": fn, "true_positive": tp},
    }


def main():
    parser = argparse.ArgumentParser(
        description="Validate and train the Bongabong 24-hour fire-occurrence model."
    )
    parser.add_argument("csv_path", type=Path, help="Path to the labeled CSV file")
    parser.add_argument(
        "--output",
        type=Path,
        default=Path(__file__).resolve().parent.parent
        / "backend"
        / "resources"
        / "ml"
        / "fire_risk_model.json",
        help="Path for the model artifact (default: backend/resources/ml/fire_risk_model.json)",
    )
    args = parser.parse_args()

    rows = load_rows(args.csv_path)
    selected_l2_strength, tuning = tune_l2_strength(rows)
    folds, validation_summary = walk_forward_validation(rows, selected_l2_strength)
    means, scales, categories, feature_names = fit_schema(rows)
    encoded_rows = [(encode(row, means, scales, categories), row[TARGET]) for row in rows]
    intercept, weights = train(
        encoded_rows,
        len(feature_names),
        l2_strength=selected_l2_strength,
    )
    optimization = optimization_diagnostics(
        encoded_rows,
        intercept,
        weights,
        selected_l2_strength,
    )
    if not optimization["converged"]:
        raise RuntimeError(
            "Model optimization did not converge within the configured training iterations; "
            "increase ITERATIONS before writing a model artifact."
        )
    positive_count = sum(row[TARGET] for row in rows)

    model = {
        "model_type": "standardized_logistic_regression",
        "model_version": "mdrrmo-bongabong-500m-24h-v3",
        "model_status": "staff_decision_support",
        "target": TARGET,
        "forecast_horizon": "next_24_hours",
        "prediction_radius_meters": 500,
        "outcome_scope": "verified fire occurrence within 500 meters of the input coordinates during the next 24 hours",
        "measurement_timing": "features are recorded before the forecast period, as confirmed by MDRRMO staff per user report",
        "source_filename": args.csv_path.name,
        "source_origin": "MDRRMO staff verified dataset labels, pre-forecast measurement timing, and the 500-meter point-level scope, as confirmed by the user.",
        "training_rows": len(rows),
        "source_rows": len(rows),
        "source_positive_rows": positive_count,
        "training_date_range": [
            min(row["_date"] for row in rows).isoformat(),
            max(row["_date"] for row in rows).isoformat(),
        ],
        "tuning": tuning,
        "training_config": {
            "learning_rate": LEARNING_RATE,
            "iterations": ITERATIONS,
            "l2_strength": selected_l2_strength,
            "selection_metric": tuning["selection_metric"],
            "optimization": optimization,
        },
        "validation": {
            "strategy": "walk_forward_calendar_years",
            "folds": folds,
            "summary": validation_summary,
        },
        "baseline_comparison": {
            "majority_class_accuracy": validation_summary["majority_class_baseline_accuracy"],
            "model_accuracy": validation_summary["accuracy"],
            "accuracy_lift": validation_summary["accuracy"]
            - validation_summary["majority_class_baseline_accuracy"],
        },
        "threshold": 0.5,
        "intercept": intercept,
        "features": feature_names,
        "weights": weights,
        "numeric_means": means,
        "numeric_scales": scales,
        "numeric_ranges": numeric_ranges(rows),
        "categories": categories,
        "limitations": [
            "Validation reflects historical holdouts from Bongabong and does not establish accuracy outside the municipality or in changed conditions.",
            "Some verified fires were missed at the reference threshold; a score below threshold is not an all-clear.",
            "Model scores have not been independently calibrated.",
            "Smoke, CO, incident type, risk score, and risk level were excluded to reduce post-event and target leakage.",
            "Use as staff decision support only. Do not automatically create, publish, or suppress public alerts from this model.",
        ],
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(model, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({
        "model_path": str(args.output.resolve()),
        "source_rows": len(rows),
        "training_rows": len(rows),
        "selected_l2_strength": selected_l2_strength,
        "tuning": tuning,
        "validation": validation_summary,
        "warning": "Staff decision support only. A below-threshold score does not rule out fire.",
    }, indent=2))


if __name__ == "__main__":
    main()
