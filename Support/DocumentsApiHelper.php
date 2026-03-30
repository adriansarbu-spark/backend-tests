<?php

declare(strict_types=1);

final class DocumentsApiHelper
{
    /**
     * Upload a document (multipart) for integration tests.
     *
     * Unlike createDocumentForFlow(), this method returns raw status/json/raw so
     * tests can assert on error behavior (e.g. 403/422 for missing certificates).
     *
     * Endpoint: POST /publicapi/v1/documents
     */
    public static function uploadDocumentForFlow(
        string $userBearer,
        string $documentName,
        string $signatureLevel,
        string $pdfContent,
        ?string $apiBase = null,
        ?string $uploadFilename = null
    ): array {
        $apiBase = $apiBase ?? (API_URL . 'documents');
        $uploadFilename = $uploadFilename ?? 'flow-test.pdf';

        [$status, $json, $raw] = ApiAuthHelper::apiRequest('POST', $apiBase, $userBearer, [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => $pdfContent,
                    'filename' => $uploadFilename,
                    'headers' => ['Content-Type' => 'application/pdf'],
                ],
                ['name' => 'name', 'contents' => $documentName],
                ['name' => 'signature_level', 'contents' => $signatureLevel],
            ],
        ]);

        // Environment guardrail (consistent with createDocumentForFlow()).
        if ($status === 500) {
            $errors = is_array($json) ? (array)($json['error'] ?? []) : [];
            $joined = implode(' | ', array_map('strval', $errors));
            if (stripos($joined, 'error_directory_not_writable') !== false || stripos($raw, 'error_directory_not_writable') !== false) {
                test()->markTestSkipped('Environment issue: upload directory is not writable for document create flow.');
            }
        }

        return [$status, $json, $raw];
    }

    /**
     * Create a small in-memory PDF document for integration flows.
     *
     * Endpoint: POST /publicapi/v1/documents
     * Rationale: centralizes upload payload + handles known environment failures
     * (e.g. upload directory not writable) by skipping instead of failing.
     *
     * Returns: [$apiBase, $uuid]
     */
    public static function createDocumentForFlow(string $userBearer, ?string $apiBase = null): array
    {
        $apiBase = $apiBase ?? (API_URL . 'documents');
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
        $documentName = 'Flow test ' . gmdate('YmdHis');

        [$createStatus, $createJson, $createRaw] = self::uploadDocumentForFlow(
            $userBearer,
            $documentName,
            'SIMPLE',
            $pdfContent,
            $apiBase,
            'flow-test.pdf'
        );

        expect($createStatus, 'Create response: ' . substr($createRaw, 0, 500))->toBe(200);
        expect(is_array($createJson))->toBeTrue();
        $uuid = (string)($createJson['data']['uuid'] ?? '');
        expect($uuid)->not->toBe('');

        return [$apiBase, $uuid];
    }
}
