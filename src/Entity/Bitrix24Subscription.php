<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\Bitrix24SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Bitrix24SubscriptionRepository::class)]
#[ORM\Table(name: 'bitrix24_subscriptions')]
#[ORM\Index(columns: ['bitrix24_user_id'], name: 'idx_b24_user_id')]
#[ORM\Index(columns: ['is_active'], name: 'idx_b24_is_active')]
class Bitrix24Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $bitrix24UserId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bitrix24UserName = null;

    #[ORM\Column(length: 255)]
    private ?string $portalUrl = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastComplimentAt = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $weekdayTime = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $weekendTime = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $historyContextSize = 1;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $weekendEnabled = false;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->weekdayTime = new \DateTime('10:25:00');
        $this->weekendTime = new \DateTime('10:25:00');
        $this->historyContextSize = 1;
        $this->weekendEnabled = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBitrix24UserId(): ?int
    {
        return $this->bitrix24UserId;
    }

    public function setBitrix24UserId(int $bitrix24UserId): static
    {
        $this->bitrix24UserId = $bitrix24UserId;

        return $this;
    }

    public function getBitrix24UserName(): ?string
    {
        return $this->bitrix24UserName;
    }

    public function setBitrix24UserName(?string $bitrix24UserName): static
    {
        $this->bitrix24UserName = $bitrix24UserName;

        return $this;
    }

    public function getPortalUrl(): ?string
    {
        return $this->portalUrl;
    }

    public function setPortalUrl(string $portalUrl): static
    {
        $this->portalUrl = $portalUrl;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getLastComplimentAt(): ?\DateTimeInterface
    {
        return $this->lastComplimentAt;
    }

    public function setLastComplimentAt(?\DateTimeInterface $lastComplimentAt): static
    {
        $this->lastComplimentAt = $lastComplimentAt;

        return $this;
    }

    public function getWeekdayTime(): ?\DateTimeInterface
    {
        return $this->weekdayTime;
    }

    public function setWeekdayTime(\DateTimeInterface $weekdayTime): static
    {
        $this->weekdayTime = $weekdayTime;

        return $this;
    }

    public function getWeekendTime(): ?\DateTimeInterface
    {
        return $this->weekendTime;
    }

    public function setWeekendTime(\DateTimeInterface $weekendTime): static
    {
        $this->weekendTime = $weekendTime;

        return $this;
    }

    public function getHistoryContextSize(): int
    {
        return $this->historyContextSize;
    }

    public function setHistoryContextSize(int $historyContextSize): static
    {
        $this->historyContextSize = $historyContextSize;

        return $this;
    }

    public function isWeekendEnabled(): bool
    {
        return $this->weekendEnabled;
    }

    public function setWeekendEnabled(bool $weekendEnabled): static
    {
        $this->weekendEnabled = $weekendEnabled;

        return $this;
    }
}
