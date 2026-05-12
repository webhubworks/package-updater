<?php

namespace App\DataTransferObjects;

final readonly class RepoUpdateResult
{
    public function __construct(
        public string $repoPath,
        public string $status,
        public ?string $branch,
        public string $message,
        public bool $hasUncommittedLock = false,
        public ?string $logPath = null,
        public ?string $previousVersion = null,
        public ?string $installedVersion = null,
        public bool $prepRan = false,
        public ?int $testsFailed = null,
        public ?string $testsSummary = null,
        public ?string $prepLogPath = null,
        public bool $crawlerRan = false,
        public bool $crawlerFailed = false,
        public ?string $crawlerLogPath = null,
        /** @var list<string> */
        public array $crawlerServerErrorUrls = [],
        public ?string $transcriptPath = null,
    ) {}

    public static function success(
        string $path,
        string $branch,
        bool $hasUncommittedLock,
        ?string $previousVersion = null,
        ?string $installedVersion = null,
        bool $prepRan = false,
        ?int $testsFailed = null,
        ?string $testsSummary = null,
        ?string $prepLogPath = null,
        bool $crawlerRan = false,
        bool $crawlerFailed = false,
        ?string $crawlerLogPath = null,
        array $crawlerServerErrorUrls = [],
        ?string $transcriptPath = null,
    ): self {
        return new self(
            repoPath: $path,
            status: 'success',
            branch: $branch,
            message: 'Updated',
            hasUncommittedLock: $hasUncommittedLock,
            previousVersion: $previousVersion,
            installedVersion: $installedVersion,
            prepRan: $prepRan,
            testsFailed: $testsFailed,
            testsSummary: $testsSummary,
            prepLogPath: $prepLogPath,
            crawlerRan: $crawlerRan,
            crawlerFailed: $crawlerFailed,
            crawlerLogPath: $crawlerLogPath,
            crawlerServerErrorUrls: $crawlerServerErrorUrls,
            transcriptPath: $transcriptPath,
        );
    }

    public static function skipped(string $path, string $message, ?string $transcriptPath = null): self
    {
        return new self($path, 'skipped', null, $message, transcriptPath: $transcriptPath);
    }

    public static function failed(string $path, string $message, ?string $branch = null, ?string $logPath = null, ?string $transcriptPath = null): self
    {
        return new self(
            repoPath: $path,
            status: 'failed',
            branch: $branch,
            message: $message,
            logPath: $logPath,
            transcriptPath: $transcriptPath,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'repoPath' => $this->repoPath,
            'status' => $this->status,
            'branch' => $this->branch,
            'message' => $this->message,
            'hasUncommittedLock' => $this->hasUncommittedLock,
            'logPath' => $this->logPath,
            'previousVersion' => $this->previousVersion,
            'installedVersion' => $this->installedVersion,
            'prepRan' => $this->prepRan,
            'testsFailed' => $this->testsFailed,
            'testsSummary' => $this->testsSummary,
            'prepLogPath' => $this->prepLogPath,
            'crawlerRan' => $this->crawlerRan,
            'crawlerFailed' => $this->crawlerFailed,
            'crawlerLogPath' => $this->crawlerLogPath,
            'crawlerServerErrorUrls' => $this->crawlerServerErrorUrls,
            'transcriptPath' => $this->transcriptPath,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            repoPath: $data['repoPath'],
            status: $data['status'],
            branch: $data['branch'] ?? null,
            message: $data['message'],
            hasUncommittedLock: $data['hasUncommittedLock'] ?? false,
            logPath: $data['logPath'] ?? null,
            previousVersion: $data['previousVersion'] ?? null,
            installedVersion: $data['installedVersion'] ?? null,
            prepRan: $data['prepRan'] ?? false,
            testsFailed: $data['testsFailed'] ?? null,
            testsSummary: $data['testsSummary'] ?? null,
            prepLogPath: $data['prepLogPath'] ?? null,
            crawlerRan: $data['crawlerRan'] ?? false,
            crawlerFailed: $data['crawlerFailed'] ?? false,
            crawlerLogPath: $data['crawlerLogPath'] ?? null,
            crawlerServerErrorUrls: is_array($data['crawlerServerErrorUrls'] ?? null) ? array_values($data['crawlerServerErrorUrls']) : [],
            transcriptPath: $data['transcriptPath'] ?? null,
        );
    }
}
