<?php

namespace ConductorAppOrchestration\Wait;

/**
 * Fetches the URL with ext-curl, in-process: no shell and no helper binary. Redirects are not
 * followed, so a 301/302 is a status like any other; list it in `status` or point at the final URL.
 */
final class CurlHttpProbe implements HttpProbeInterface
{
    public function probe(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        bool $verifyTls
    ): HttpProbeResult {
        $handle = curl_init();
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => 'HEAD' === $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if (null !== $body) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        if (false === $responseBody) {
            return HttpProbeResult::transportError(curl_error($handle) ?: 'no response');
        }

        return new HttpProbeResult((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string) $responseBody);
    }
}
