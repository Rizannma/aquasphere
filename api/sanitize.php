<?php
/**
 * Simple input sanitization helpers.
 * These functions normalize and strip potentially unsafe content before it reaches queries or output.
 * NOTE: Always pair with prepared statements and output escaping on render.
 */

function sanitize_string($value, $max_len = 255) {
    if ($value === null) return null;
    $v = trim((string)$value);
    // strip tags to reduce XSS vectors
    $v = strip_tags($v);
    if ($max_len > 0) {
        $v = mb_substr($v, 0, $max_len);
    }
    return $v;
}

function sanitize_email($value, $max_len = 255) {
    $v = sanitize_string($value, $max_len);
    return filter_var($v, FILTER_SANITIZE_EMAIL);
}

function sanitize_int($value) {
    return intval($value);
}

function sanitize_float($value) {
    return floatval($value);
}

function sanitize_array_recursive($data) {
    if (is_array($data)) {
        $clean = [];
        foreach ($data as $k => $v) {
            $clean[$k] = sanitize_array_recursive($v);
        }
        return $clean;
    }
    if (is_object($data)) {
        foreach ($data as $k => $v) {
            $data->$k = sanitize_array_recursive($v);
        }
        return $data;
    }
    // primitives
    if (is_string($data)) return sanitize_string($data, 10240); // allow longer JSON-ish strings
    if (is_int($data)) return $data;
    if (is_float($data)) return $data;
    return $data;
}

