<?php

declare(strict_types=1);

if (!class_exists(TestableControllerPublicAPIV1AccountCompanies::class)) {
    /**
     * Test harness for {@see ControllerPublicAPIV1AccountCompanies}: captures POST body,
     * counts framework hooks, and avoids real {@see PublicAPIController::checkPlugin} /
     * {@see PublicAPIController::sendResponse} side effects.
     */
    final class TestableControllerPublicAPIV1AccountCompanies extends ControllerPublicAPIV1AccountCompanies
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        public int $getPostCalls = 0;

        /** @param array<string, mixed> $payload */
        public function setPostPayload(array $payload): void
        {
            $this->postPayload = $payload;
        }

        public function checkPlugin(): void
        {
            ++$this->checkPluginCalls;
        }

        /** @return null */
        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }

        /** @return array<string, mixed> */
        public function getPost(): array
        {
            ++$this->getPostCalls;

            return $this->postPayload;
        }
    }
}

/** Minimal customer stub: only {@see getId()} is used by the controller. */
final class AccountCompaniesTestCustomerStub
{
    public function __construct(private readonly int $customerId)
    {
    }

    public function getId(): int
    {
        return $this->customerId;
    }
}

/** Simulates a session where {@see getId()} is missing; cast to int yields 0 (unauthenticated). */
final class AccountCompaniesNullIdCustomerStub
{
    public function getId(): ?int
    {
        return null;
    }
}

/** Records {@see model()} routes without touching the filesystem or registry models. */
final class AccountCompaniesRecordingLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;
    }
}
