<?php

declare(strict_types=1);

/**
 * SigningFixtures
 *
 * Helper utilities for HTTP-level signing feature tests that need to:
 * - Insert test data (documents + signers + visibility) into the shared dev DB
 * - Keep that data for manual inspection
 * - Delete previously created fixture rows on the *next* test run
 *
 * This helper intentionally uses the existing OpenCart registry + model layer
 * to avoid duplicating SQL business rules (status IDs, sign_code generation, visibility creation).
 *
 * IMPORTANT
 * - This mutates the shared dev DB. Only use for opt-in feature tests.
 * - Cleanup is explicit DELETEs (no cross-process transactions).
 *
 * Tables involved (no prefix assumed; uses DB_PREFIX):
 * - documents
 * - document_signers
 * - document_user_visibility
 */

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'signing.php';

// Load DB credentials (DB_DRIVER/DB_HOSTNAME/DB_USERNAME/DB_PASSWORD/DB_DATABASE/DB_PORT/DB_PREFIX).
require_once DIR_CONFIG_NOACCESS . 'db.php';

// Minimal OpenCart engine/bootstrap pieces for model loading.
require_once DIR_SYSTEM . 'engine/model.php';
require_once DIR_SYSTEM . 'engine/proxy.php';
require_once DIR_SYSTEM . 'engine/loader.php';
require_once DIR_SYSTEM . 'library/db.php';
require_once DIR_SYSTEM . 'library/db/' . DB_DRIVER . '.php';
/**
 * Minimal controller wrapper so feature tests can use controller services ($db, $load, etc.)
 * without going through HTTP routing.
 */
final class SigningFixturesController extends ControllerPublicAPIV1Signing
{
    // No extra methods required; we only use $this->db and $this->load->model().
}

final class SigningFixtures
{
    /**
     * Create a controller instance with a real DB connection and a Loader capable of loading models.
     * We register a minimal Event stub because Loader's model callbacks call $event->trigger(...).
     */
    public static function bootstrapController(): SigningFixturesController
    {
        $registry = new Registry();

        // Minimal event stub: return null for all triggers.
        $registry->set('event', new class {
            public function trigger(string $route, array $args = [])
            {
                return null;
            }
        });

        $db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
        $registry->set('db', $db);

        $loader = new Loader($registry);
        $registry->set('load', $loader);

        return new SigningFixturesController($registry);
    }

    /**
     * Build a controller from an existing Registry.
     * Use this when your test suite already bootstraps DB and other services.
     */
    public static function controllerFromRegistry(Registry $registry): SigningFixturesController
    {
        if (!$registry->has('event')) {
            $registry->set('event', new class {
                public function trigger(string $route, array $args = [])
                {
                    return null;
                }
            });
        }

        if (!$registry->has('load')) {
            $registry->set('load', new Loader($registry));
        }

        $controller = new SigningFixturesController($registry);

        if (!isset($controller->db)) {
            throw new RuntimeException(
                'SigningFixtures requires $controller->db to be set in the provided Registry.'
            );
        }

        return $controller;
    }

    /**
     * Delete fixtures created by previous runs, identified by a marker in documents.name.
     *
     * The current run should call this once at the beginning. The current run's fixtures
     * are intentionally *not* deleted at the end, so they remain for manual inspection.
     */
    public static function cleanupByNameTag(SigningFixturesController $controller, string $nameTag): void
    {
        $db = $controller->db;
        $tagLike = '%' . $nameTag . '%';

        $query = $db->query(
            "SELECT document_id FROM `" . DB_PREFIX . "documents`
             WHERE name LIKE '" . $db->escape($tagLike) . "'"
        );

        foreach ($query->rows as $row) {
            $documentId = (int)($row['document_id'] ?? 0);
            if ($documentId <= 0) {
                continue;
            }

            // Child tables first.
            $db->query(
                "DELETE FROM `" . DB_PREFIX . "document_user_visibility`
                 WHERE document_id = '" . $documentId . "'"
            );
            $db->query(
                "DELETE FROM `" . DB_PREFIX . "document_signers`
                 WHERE document_id = '" . $documentId . "'"
            );

            // Then the document row.
            $db->query(
                "DELETE FROM `" . DB_PREFIX . "documents`
                 WHERE document_id = '" . $documentId . "'"
            );
        }
    }


    // simple function to create a document
    public static function createDocument(SigningFixturesController $controller, array $opts): void {
        $controller->load->model('signing/document');
        $uuid = class_exists(\Ramsey\Uuid\Uuid::class)
            ? \Ramsey\Uuid\Uuid::uuid4()->toString()
            : self::uuidV4();
        $controller->model_signing_document->createDocument([
            'uuid' => $uuid,
            'name' => 'Test Document',
            'owner_customer_role_id' => $opts['owner_customer_role_id'],
            'name_tag' => $opts['name_tag'],
        ]);
    }

    /**
     * Create a signing document and add a signer (user1) to it.
     *
     * Returns:
     * - document_id
     * - document_uuid
     * - sign_code (for user1 signer)
     * - document_name
     *
     * Notes:
     * - `ModelSigningDocument::createDocument()` creates the document in DRAFT and creates owner visibility.
     * - `ModelSigningSigner::addSigner()` inserts into document_signers and creates signer visibility.
     * - We then look up the generated sign_code from document_signers by document_id+email.
     */
    public static function createDocumentWithSigner(
        SigningFixturesController $controller,
        array $opts
    ): array {
        $defaults = [
            // Marker used for later cleanup.
            'name_tag' => '_testing_feature_signing',
            // Required owner role id in documents.owner_customer_role_id.
            'owner_customer_role_id' => 0,
            // Signer email (the intended signer).
            'signer_email' => 'user1@example.com',
            // Document name prefix (we append tag + run id).
            'name_prefix' => 'SIGNING_FEATURE_FIXTURE',
        ];
        $opts = array_merge($defaults, $opts);

        $ownerRoleId = (int)$opts['owner_customer_role_id'];
        if ($ownerRoleId <= 0) {
            throw new InvalidArgumentException('owner_customer_role_id must be provided and > 0');
        }

        $signerEmail = (string)$opts['signer_email'];
        $tag = (string)$opts['name_tag'];

        // Ensure the models can be loaded via $controller->load->model(...)
        $controller->load->model('signing/document');
        $controller->load->model('signing/signer');

        /** @var Proxy $docModel */
        $docModel = $controller->model_signing_document;
        /** @var Proxy $signerModel */
        $signerModel = $controller->model_signing_signer;

        // Generate stable-ish identifiers.
        $uuid = class_exists(\Ramsey\Uuid\Uuid::class)
            ? \Ramsey\Uuid\Uuid::uuid4()->toString()
            : self::uuidV4();

        $runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $docName = (string)$opts['name_prefix'] . '::' . $tag . '::' . $runId;

        // documents.original_file_code/current_file_code are required; use a random UUID-like string.
        $fileCode = class_exists(\Ramsey\Uuid\Uuid::class)
            ? \Ramsey\Uuid\Uuid::uuid4()->toString()
            : self::uuidV4();

        $documentId = (int)$docModel->createDocument([
            'uuid' => $uuid,
            'owner_customer_role_id' => $ownerRoleId,
            'name' => $docName,
            'original_file_code' => $fileCode,
            // signature_level/expires_at optional; left default.
        ]);

        // Add signer. This generates sign_code and inserts a DRAFT signer row by default.
        $signerModel->addSigner($documentId, [
            'customer_role_id' => null,
            'email' => $signerEmail,
            'signing_order' => 1,
            'parallel_group' => 0,
            'signing_type' => 'SIGNATURE',
            'send_email' => 0,
        ]);

        // Read back sign_code for the inserted signer.
        $db = $controller->db;
        $signerRow = $db->query(
            "SELECT sign_code
             FROM `" . DB_PREFIX . "document_signers`
             WHERE document_id = '" . (int)$documentId . "'
               AND email = '" . $db->escape($signerEmail) . "'
             ORDER BY document_signer_id DESC
             LIMIT 1"
        )->row;

        $signCode = $signerRow['sign_code'] ?? null;
        if (!is_string($signCode) || $signCode === '') {
            throw new RuntimeException('Failed to read generated sign_code for signer fixture');
        }

        return [
            'document_id' => $documentId,
            'document_uuid' => $uuid,
            'document_name' => $docName,
            'sign_code' => $signCode,
        ];
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

