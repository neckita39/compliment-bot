<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\Bitrix24ComplimentHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Bitrix24ComplimentHistoryRepository::class)]
#[ORM\Table(name: 'bitrix24_compliment_history')]
#[ORM\Index(columns: ['subscription_id', 'sent_at'], name: 'idx_b24_subscription_sent_at')]
class Bitrix24ComplimentHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Bitrix24Subscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Bitrix24Subscription $subscription = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $complimentText = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $sentAt = null;

    public function __construct()
    {
        $this->sentAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubscription(): ?Bitrix24Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(Bitrix24Subscription $subscription): static
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getComplimentText(): ?string
    {
        return $this->complimentText;
    }

    public function setComplimentText(string $complimentText): static
    {
        $this->complimentText = $complimentText;

        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeInterface $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }
}
