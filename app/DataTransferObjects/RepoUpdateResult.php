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
    ) {}

    public static function success(
        string $path,
        string $branch,
        bool $hasUncommittedLock,
        ?string $previousVersion = null,
        ?string $installedVersion = null,
    ): self {
        return new self(
            repoPath: $path,
            status: 'success',
            branch: $branch,
            message: 'Updated',
            hasUncommittedLock: $hasUncommittedLock,
            previousVersion: $previousVersion,
            installedVersion: $installedVersion,
        );
    }

    public static function skipped(string $path, string $message): self
    {
        return new self($path, 'skipped', null, $message);
    }

    public static function failed(string $path, string $message, ?string $branch = null, ?string $logPath = null): self
    {
        return new self(
            repoPath: $path,
            status: 'failed',
            branch: $branch,
            message: $message,
            logPath: $logPath,
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
        );
    }
}
