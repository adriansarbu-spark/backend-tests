<?php

declare(strict_types=1);

final class DocumentsApiHelper
{
    /**
     * Document uuid from GET /documents/{uuid} (legacy flat or group envelope).
     */
    public static function documentUuidFromGetResponse(array $json, ?string $requestedUuid = null): string
    {
        $data = $json['data'] ?? [];
        if (!is_array($data)) {
            return '';
        }

        if (!empty($data['uuid'])) {
            return (string)$data['uuid'];
        }

        if ($requestedUuid !== null && $requestedUuid !== '') {
            foreach ((array)($data['documents'] ?? []) as $doc) {
                if ((string)($doc['uuid'] ?? '') === $requestedUuid) {
                    return $requestedUuid;
                }
            }
        }

        $current = (string)($data['current_document_uuid'] ?? '');
        if ($current !== '') {
            return $current;
        }

        $documents = (array)($data['documents'] ?? []);
        if (count($documents) === 1) {
            return (string)($documents[0]['uuid'] ?? '');
        }

        return '';
    }

    /**
     * sign_code for a signer email from GET /documents/{uuid} (legacy flat or group envelope).
     */
    public static function signCodeFromGetDocumentResponse(array $json, string $email): string
    {
        $data = $json['data'] ?? [];
        if (!is_array($data)) {
            return '';
        }

        $emailLower = strtolower(trim($email));

        foreach ((array)($data['signers'] ?? []) as $signer) {
            if (strtolower(trim((string)($signer['email'] ?? ''))) === $emailLower) {
                $signCode = (string)($signer['sign_code'] ?? '');
                if ($signCode !== '') {
                    return $signCode;
                }
            }
        }

        foreach ((array)($data['documents'] ?? []) as $doc) {
            foreach ((array)($doc['signers'] ?? []) as $signer) {
                if (strtolower(trim((string)($signer['email'] ?? ''))) === $emailLower) {
                    $signCode = (string)($signer['sign_code'] ?? '');
                    if ($signCode !== '') {
                        return $signCode;
                    }
                }
            }
        }

        return '';
    }

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

        expect($createStatus)->toBe(200, 'Create response: ' . substr($createRaw, 0, 500));
        expect(is_array($createJson))->toBeTrue();
        $uuid = (string)($createJson['data']['uuid'] ?? '');
        expect($uuid)->not->toBe('');

        return [$apiBase, $uuid];
    }
}
