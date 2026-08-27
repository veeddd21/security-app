<?php

/**
 * Real face verification via CompreFace (self-hosted, open-source face
 * recognition: https://github.com/exadel-inc/CompreFace), with the old
 * pixel-brightness comparison kept only as a last-resort fallback so
 * check-in/check-out never hard-fails if CompreFace is unreachable.
 *
 * Configure via php-core/.env (see .env.example):
 *   COMPREFACE_BASE_URL, COMPREFACE_API_KEY,
 *   COMPREFACE_VERIFY_THRESHOLD, COMPREFACE_REVIEW_THRESHOLD,
 *   COMPREFACE_TIMEOUT_MS, COMPREFACE_DET_PROB_THRESHOLD
 */

function face_storage_absolute_path(?string $path): ?string
{
    if (!$path) {
        return null;
    }

    if (str_starts_with($path, '/storage/uploads/')) {
        return __DIR__ . '/../' . ltrim($path, '/');
    }

    return null;
}

function compreface_is_configured(): bool
{
    return env('COMPREFACE_BASE_URL', '') !== '' && env('COMPREFACE_API_KEY', '') !== '';
}

/**
 * Calls CompreFace's face verification endpoint with two local image files.
 * Returns:
 *   ['similarity' => float 0..1, 'face_detected' => true]  on a normal comparison
 *   ['similarity' => 0.0,        'face_detected' => false] if no face was found in either image
 *   null on any transport/config/HTTP error (caller should fall back)
 */
function compreface_verify_images(string $referenceAbsPath, string $selfieAbsPath): ?array
{
    if (!compreface_is_configured()) {
        return null;
    }
    if (!is_file($referenceAbsPath) || !is_file($selfieAbsPath)) {
        return null;
    }
    if (!function_exists('curl_init')) {
        error_log('CompreFace: PHP curl extension is not enabled; falling back.');
        return null;
    }

    $baseUrl = rtrim((string)env('COMPREFACE_BASE_URL'), '/');
    $apiKey = (string)env('COMPREFACE_API_KEY');
    $timeoutMs = env_int('COMPREFACE_TIMEOUT_MS', 8000);
    $detProbThreshold = env('COMPREFACE_DET_PROB_THRESHOLD', '0.8');

    $url = $baseUrl . '/api/v1/verification/verify?det_prob_threshold=' . rawurlencode((string)$detProbThreshold);

    $referenceMime = face_guess_mime($referenceAbsPath);
    $selfieMime = face_guess_mime($selfieAbsPath);

    $postFields = [
        'source_image' => new CURLFile($referenceAbsPath, $referenceMime, 'reference.jpg'),
        'target_image' => new CURLFile($selfieAbsPath, $selfieMime, 'selfie.jpg'),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => max(1000, $timeoutMs),
        CURLOPT_CONNECTTIMEOUT_MS => min(3000, max(1000, $timeoutMs)),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('CompreFace: request failed - ' . $curlError);
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("CompreFace: HTTP {$httpCode} - " . substr((string)$response, 0, 500));
        return null;
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        error_log('CompreFace: could not decode response JSON.');
        return null;
    }

    $result = $decoded['result'][0] ?? null;
    if (!$result) {
        // No face detected in one or both images.
        return ['similarity' => 0.0, 'face_detected' => false];
    }

    $matches = $result['face_matches'] ?? [];
    if (empty($matches)) {
        return ['similarity' => 0.0, 'face_detected' => false];
    }

    // Highest similarity match if multiple faces were found in the target image.
    $bestSimilarity = 0.0;
    foreach ($matches as $match) {
        $similarity = (float)($match['similarity'] ?? 0);
        if ($similarity > $bestSimilarity) {
            $bestSimilarity = $similarity;
        }
    }

    return ['similarity' => $bestSimilarity, 'face_detected' => true];
}

function face_guess_mime(string $absPath): string
{
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    return match ($ext) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

/**
 * Legacy fallback: 24x24 grayscale pixel-brightness comparison. This is NOT
 * real face recognition (no face detection, no alignment) - it only tells
 * you the two images have similar overall brightness/exposure. It exists
 * solely so attendance capture keeps working if CompreFace is not
 * configured or is temporarily unreachable.
 */
function legacy_grayscale_compare(string $referenceAbsPath, string $selfieAbsPath): ?float
{
    $ref = @imagecreatefromstring((string)@file_get_contents($referenceAbsPath));
    $shot = @imagecreatefromstring((string)@file_get_contents($selfieAbsPath));

    if (!$ref || !$shot) {
        if ($ref) { imagedestroy($ref); }
        if ($shot) { imagedestroy($shot); }
        return null;
    }

    $size = 24;
    $refCanvas = imagecreatetruecolor($size, $size);
    $shotCanvas = imagecreatetruecolor($size, $size);
    imagecopyresampled($refCanvas, $ref, 0, 0, 0, 0, $size, $size, imagesx($ref), imagesy($ref));
    imagecopyresampled($shotCanvas, $shot, 0, 0, 0, 0, $size, $size, imagesx($shot), imagesy($shot));

    $totalDiff = 0;
    $pixels = $size * $size;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $refRgb = imagecolorat($refCanvas, $x, $y);
            $shotRgb = imagecolorat($shotCanvas, $x, $y);
            $refGray = ((($refRgb >> 16) & 0xFF) * 0.299) + ((($refRgb >> 8) & 0xFF) * 0.587) + (($refRgb & 0xFF) * 0.114);
            $shotGray = ((($shotRgb >> 16) & 0xFF) * 0.299) + ((($shotRgb >> 8) & 0xFF) * 0.587) + (($shotRgb & 0xFF) * 0.114);
            $totalDiff += abs($refGray - $shotGray);
        }
    }

    imagedestroy($refCanvas);
    imagedestroy($shotCanvas);
    imagedestroy($ref);
    imagedestroy($shot);

    $maxDiff = 255 * $pixels;
    return round(max(0.0, min(1.0, 1.0 - ($totalDiff / $maxDiff))), 4);
}

/**
 * Main entry point used by both the web app (index.php) and the Flutter
 * app's API (api.php). Keeps the same return shape as before:
 *   ['score' => float|null, 'passed' => 0|1, 'status' => string, 'method' => string]
 */
function compare_guard_images(?string $referencePath, ?string $selfiePath): array
{
    $referenceAbs = face_storage_absolute_path($referencePath);
    $selfieAbs = face_storage_absolute_path($selfiePath);

    if (!$referenceAbs || !is_file($referenceAbs) || !$selfieAbs || !is_file($selfieAbs)) {
        return [
            'score' => null,
            'passed' => 0,
            'status' => 'no_reference',
            'method' => 'no_reference',
        ];
    }

    $matchThreshold = env_float('COMPREFACE_VERIFY_THRESHOLD', 0.78);
    $reviewThreshold = env_float('COMPREFACE_REVIEW_THRESHOLD', 0.65);

    $compreface = compreface_verify_images($referenceAbs, $selfieAbs);

    if ($compreface !== null) {
        if (!$compreface['face_detected']) {
            return [
                'score' => 0.0,
                'passed' => 0,
                'status' => 'face_not_detected',
                'method' => 'compreface',
            ];
        }

        $score = round($compreface['similarity'], 4);
        if ($score >= $matchThreshold) {
            $status = 'matched';
            $passed = 1;
        } elseif ($score >= $reviewThreshold) {
            $status = 'reference_review';
            $passed = 0;
        } else {
            $status = 'mismatch';
            $passed = 0;
        }

        return [
            'score' => $score,
            'passed' => $passed,
            'status' => $status,
            'method' => 'compreface',
        ];
    }

    // CompreFace not configured or temporarily unreachable - fall back to the
    // legacy brightness comparison so attendance is never blocked, but flag
    // it clearly as a low-confidence method so admins know to review it.
    $legacyScore = legacy_grayscale_compare($referenceAbs, $selfieAbs);
    if ($legacyScore === null) {
        return [
            'score' => null,
            'passed' => 0,
            'status' => 'no_reference',
            'method' => 'no_reference',
        ];
    }

    return [
        'score' => $legacyScore,
        'passed' => 0,
        'status' => 'reference_review',
        'method' => 'gd_grayscale_fallback',
    ];
}