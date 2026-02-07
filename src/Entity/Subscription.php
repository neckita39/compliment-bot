<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscriptions')]
#[ORM\Index(columns: ['telegram_chat_id'], name: 'idx_telegram_chat_id')]
#[ORM\Index(columns: ['is_active'], name: 'idx_is_active')]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $telegramChatId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $telegramUsername = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $telegramFirstName = null;

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

    #[ORM\Column(length: 50)]
    private string $role = 'wife';

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        // Default times: 7:00 for weekdays, 9:00 for weekends
        $this->weekdayTime = new \DateTime('07:00:00');
        $this->weekendTime = new \DateTime('09:00:00');
        $this->role = 'wife';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramChatId(): ?string
    {
        return $this->telegramChatId;
    }

    public function setTelegramChatId(string $telegramChatId): static
    {
        $this->telegramChatId = $telegramChatId;

        return $this;
    }

    public function getTelegramUsername(): ?string
    {
        return $this->telegramUsername;
    }

    public function setTelegramUsername(?string $telegramUsername): static
    {
        $this->telegramUsername = $telegramUsername;

        return $this;
    }

    public function getTelegramFirstName(): ?string
    {
        return $this->telegramFirstName;
    }

    public function setTelegramFirstName(?string $telegramFirstName): static
    {
        $this->telegramFirstName = $telegramFirstName;

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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }
}
