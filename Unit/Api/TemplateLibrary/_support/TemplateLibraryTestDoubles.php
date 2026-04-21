<?php

declare(strict_types=1);

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (!class_exists(TestableControllerPublicAPIV1EsignTplLibrary::class)) {
    class TestableControllerPublicAPIV1EsignTplLibrary extends ControllerPublicAPIV1EsignTplLibrary
    {
        public function listLibraries()
        {
            return parent::listLibraries();
        }

        public function getLibrary($uuid)
        {
            return parent::getLibrary($uuid);
        }

        public function getLibraryVersions($uuid)
        {
            return parent::getLibraryVersions($uuid);
        }

        public function createLibrary()
        {
            return parent::createLibrary();
        }

        public function updateLibrary($uuid)
        {
            return parent::updateLibrary($uuid);
        }

        public function deleteLibrary($uuid)
        {
            return parent::deleteLibrary($uuid);
        }

        public function publishLibrary($uuid)
        {
            return parent::publishLibrary($uuid);
        }

        public function archiveLibrary($uuid)
        {
            return parent::archiveLibrary($uuid);
        }

        public function editPublishedLibrary($uuid)
        {
            return parent::editPublishedLibrary($uuid);
        }

        public function createLibraryVersion($library_uuid)
        {
            return parent::createLibraryVersion($library_uuid);
        }

        public function updateLibraryVersion($library_uuid, $version_uuid)
        {
            return parent::updateLibraryVersion($library_uuid, $version_uuid);
        }

        public function replaceLibraryParties($library_uuid, $version_uuid)
        {
            return parent::replaceLibraryParties($library_uuid, $version_uuid);
        }

        public function replaceLibrarySmartfields($library_uuid, $version_uuid)
        {
            return parent::replaceLibrarySmartfields($library_uuid, $version_uuid);
        }

        public function publishLibraryVersion($library_uuid, $version_uuid)
        {
            return parent::publishLibraryVersion($library_uuid, $version_uuid);
        }

        public function addLibraryToMyTemplates($library_uuid)
        {
            return parent::addLibraryToMyTemplates($library_uuid);
        }

        public function cloneLibraryToNewLibrary($library_uuid)
        {
            return parent::cloneLibraryToNewLibrary($library_uuid);
        }
    }
}

