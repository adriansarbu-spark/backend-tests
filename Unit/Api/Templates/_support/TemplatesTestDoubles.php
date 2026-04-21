<?php

declare(strict_types=1);

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (!class_exists(TestableControllerPublicAPIV1EsignTplTemplates::class)) {
    class TestableControllerPublicAPIV1EsignTplTemplates extends ControllerPublicAPIV1EsignTplTemplates
    {
        public function createTemplate()
        {
            return parent::createTemplate();
        }

        public function listTemplates()
        {
            return parent::listTemplates();
        }

        public function getTemplate($uuid)
        {
            return parent::getTemplate($uuid);
        }

        public function getTemplateVersions($template_uuid)
        {
            return parent::getTemplateVersions($template_uuid);
        }

        public function updateTemplate($uuid)
        {
            return parent::updateTemplate($uuid);
        }

        public function deleteTemplate($uuid)
        {
            return parent::deleteTemplate($uuid);
        }

        public function publishTemplate($uuid)
        {
            return parent::publishTemplate($uuid);
        }

        public function archiveTemplate($uuid)
        {
            return parent::archiveTemplate($uuid);
        }

        public function cloneTemplate($uuid)
        {
            return parent::cloneTemplate($uuid);
        }

        public function editPublishedTemplate($uuid)
        {
            return parent::editPublishedTemplate($uuid);
        }

        public function createVersion($template_uuid)
        {
            return parent::createVersion($template_uuid);
        }

        public function updateVersion($template_uuid, $version_uuid)
        {
            return parent::updateVersion($template_uuid, $version_uuid);
        }

        public function replaceParties($template_uuid, $version_uuid)
        {
            return parent::replaceParties($template_uuid, $version_uuid);
        }

        public function replaceSmartfields($template_uuid, $version_uuid)
        {
            return parent::replaceSmartfields($template_uuid, $version_uuid);
        }

        public function publishVersion($template_uuid, $version_uuid)
        {
            return parent::publishVersion($template_uuid, $version_uuid);
        }
    }
}

