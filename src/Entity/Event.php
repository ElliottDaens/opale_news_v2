<?php

namespace App\Entity;

use App\Enum\EventStatus;
use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;

/*
Event

QUOI : Agrégat métier « manifestation sur la Côte d’Opale » : infos pratiques, médias, workflow de modération, suivi d’index vectorielle.

COMMENT : Attributs Doctrine, callbacks lifecycle, transitions `approve` / `reject` / soft-delete, texte enrichi pour embedding et exposition JSON vers le front.

OÙ : Table `events`, filtrée par `SoftDeleteFilter` ; manipulé par soumission publique, admin et recherche.

POURQUOI : Centraliser règles de publication, traçabilité modération et cohérence avec Pinecone.
*/

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\HasLifecycleCallbacks]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $titre;

    #[ORM\Column(length: 150)]
    private string $nomOrganisateur;

    #[ORM\Column(length: 180)]
    private string $emailContact;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $prix = null;

    #[ORM\Column(length: 60)]
    private string $categorie;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column(length: 255)]
    private string $adresse;

    #[ORM\Column(length: 120)]
    private string $ville;

    #[ORM\Column(length: 5)]
    private string $codePostal;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageCouverture = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageBanniere = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: EventStatus::class)]
    private EventStatus $status = EventStatus::Pending;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: EventStatus::class)]
    private ?EventStatus $previousStatus = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $moderatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, name: 'deleted_at')]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private bool $indexed = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $updateToken = null;

    private static ?AsciiSlugger $slugger = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitre(): string { return $this->titre; }
    public function setTitre(string $v): self { $this->titre = $v; return $this; }

    public function getNomOrganisateur(): string { return $this->nomOrganisateur; }
    public function setNomOrganisateur(string $v): self { $this->nomOrganisateur = $v; return $this; }

    public function getEmailContact(): string { return $this->emailContact; }
    public function setEmailContact(string $v): self { $this->emailContact = $v; return $this; }

    public function getPrix(): ?string { return $this->prix; }
    public function getPrixDisplay(): string { return $this->prix ?? 'Gratuit'; }
    public function setPrix(?string $v): self { $this->prix = $v === '' ? null : $v; return $this; }

    public function getCategorie(): string { return $this->categorie; }
    public function setCategorie(string $v): self { $this->categorie = $v; return $this; }

    public function getStartDate(): \DateTimeImmutable { return $this->startDate; }
    public function setStartDate(\DateTimeImmutable $v): self { $this->startDate = $v; return $this; }

    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $v): self { $this->endDate = $v; return $this; }

    public function getStartTime(): ?\DateTimeImmutable { return $this->startTime; }
    public function setStartTime(?\DateTimeImmutable $v): self { $this->startTime = $v; return $this; }

    public function getEndTime(): ?\DateTimeImmutable { return $this->endTime; }
    public function setEndTime(?\DateTimeImmutable $v): self { $this->endTime = $v; return $this; }

    public function getAdresse(): string { return $this->adresse; }
    public function setAdresse(string $v): self { $this->adresse = $v; return $this; }

    public function getVille(): string { return $this->ville; }
    public function setVille(string $v): self { $this->ville = $v; return $this; }

    public function getCodePostal(): string { return $this->codePostal; }
    public function setCodePostal(string $v): self { $this->codePostal = $v; return $this; }

    public function getLatitude(): ?float { return $this->latitude; }
    public function setLatitude(?float $v): self { $this->latitude = $v; return $this; }

    public function getLongitude(): ?float { return $this->longitude; }
    public function setLongitude(?float $v): self { $this->longitude = $v; return $this; }

    public function hasCoordinates(): bool { return $this->latitude !== null && $this->longitude !== null; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): self { $this->description = $v; return $this; }

    public function getImageCouverture(): ?string { return $this->imageCouverture; }
    public function setImageCouverture(?string $v): self { $this->imageCouverture = $v; return $this; }

    public function getImageBanniere(): ?string { return $this->imageBanniere; }
    public function setImageBanniere(?string $v): self { $this->imageBanniere = $v; return $this; }

    public function getStatus(): EventStatus { return $this->status; }

    public function getPreviousStatus(): ?EventStatus { return $this->previousStatus; }

    public function getModeratedAt(): ?\DateTimeImmutable { return $this->moderatedAt; }

    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function isIndexed(): bool { return $this->indexed; }
    public function setIndexed(bool $v): self { $this->indexed = $v; return $this; }

    public function getUpdateToken(): ?string { return $this->updateToken; }

    public function ensureUpdateToken(): self
    {
        if ($this->updateToken === null) {
            $this->updateToken = bin2hex(random_bytes(32));
        }

        return $this;
    }

    /**
     * Slug SEO dérivé du titre (sans persistance en base).
     */
    public function getSlug(): string
    {
        self::$slugger ??= new AsciiSlugger('fr');
        $slug = strtolower((string) self::$slugger->slug($this->titre));

        return $slug !== '' ? $slug : 'evenement';
    }

    public function getStartDateTime(): \DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Paris');
        $date = $this->startDate->format('Y-m-d');
        if ($this->startTime !== null) {
            return \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $this->startTime->format('H:i:s'), $tz)
                ?: $this->startDate->setTimezone($tz);
        }

        return \DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz)->setTime(0, 0)
            ?: $this->startDate->setTimezone($tz);
    }

    public function getEndDateTime(): \DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Paris');
        $endDate = $this->endDate ?? $this->startDate;
        $date = $endDate->format('Y-m-d');
        if ($this->endTime !== null) {
            return \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $this->endTime->format('H:i:s'), $tz)
                ?: $endDate->setTimezone($tz);
        }

        return \DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz)->setTime(23, 59, 59)
            ?: $endDate->setTimezone($tz);
    }

    public function getPriceNumeric(): ?float
    {
        if ($this->prix === null || $this->prix === '') {
            return 0.0;
        }

        if (preg_match('/gratuit/i', $this->prix)) {
            return 0.0;
        }

        if (preg_match('/(\d+[,.]?\d*)/', $this->prix, $matches)) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        return null;
    }

    public function getMonthShortLabelFr(): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Europe/Paris', null, 'MMM');

        return rtrim((string) $formatter->format($this->startDate), '.');
    }

    public function getDayLabel(): string
    {
        return $this->startDate->format('j');
    }

    public function getWeekdayLabelFr(): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'Europe/Paris', null, 'EEEE');

        return (string) $formatter->format($this->startDate);
    }

    public function approve(): self
    {
        $this->previousStatus = $this->status;
        $this->status = EventStatus::Approved;
        $this->moderatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function reject(): self
    {
        $this->previousStatus = $this->status;
        $this->status = EventStatus::Rejected;
        $this->moderatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function undoModeration(): self
    {
        if ($this->previousStatus !== null) {
            $this->status = $this->previousStatus;
            $this->previousStatus = null;
            $this->moderatedAt = null;
        }
        return $this;
    }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        return $this;
    }

    public function restore(): self
    {
        $this->deletedAt = null;
        return $this;
    }

    public function setStatus(EventStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    private const CATEGORY_KEYWORDS = [
        'Musique' => 'musique, concert, soirée musicale, spectacle musical, performance live, musique live, écouter de la musique',
        'Sport' => 'sport, activité sportive, activité physique, loisir actif, sensations, faire du sport, exercice, plein air actif',
        'Culture' => 'culture, art, artistique, exposition, musée, patrimoine, découverte culturelle, vernissage',
        'Brocante' => 'brocante, vide-greniers, marché aux puces, antiquités, antiquaire, chiner, chineurs, objets anciens, seconde main, occasion, collection, collectionneurs, troc',
        'Marché' => 'marché, market, producteurs locaux, terroir, food, produits frais, courses',
        'Gastronomie' => 'gastronomie, cuisine, restaurant, dégustation, repas, food, plats, gourmet',
        'Famille' => 'famille, enfants, parents, kids, sortie familiale, jeune public',
        'Festival' => 'festival, événement, fête populaire, célébration, animations',
        'Atelier' => 'atelier, workshop, formation, initiation, apprendre, cours pratique',
        'Conférence' => 'conférence, talk, présentation, débat, intervention, expert',
        'Découverte' => 'découverte, exploration, observation, balade, randonnée, nature',
    ];

    public function getEmbeddingText(): string
    {
        $keywords = self::CATEGORY_KEYWORDS[$this->categorie] ?? '';

        $parts = [
            $this->titre,
            $this->description,
            'Type d\'événement : ' . $this->categorie . ($keywords !== '' ? ' (' . $keywords . ')' : ''),
            'Ville : ' . $this->ville . ', sur la Côte d\'Opale (Pas-de-Calais)',
            $this->prix === null ? 'Entrée gratuite, accès libre' : 'Tarif : ' . $this->prix,
        ];

        return implode('. ', array_filter($parts)) . '.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->getSlug(),
            'titre' => $this->titre,
            'organisateur' => $this->nomOrganisateur,
            'prix' => $this->getPrixDisplay(),
            'categorie' => $this->categorie,
            'startDate' => $this->startDate->format('Y-m-d'),
            'endDate' => $this->endDate?->format('Y-m-d'),
            'startTime' => $this->startTime?->format('H:i'),
            'endTime' => $this->endTime?->format('H:i'),
            'adresse' => $this->adresse,
            'ville' => $this->ville,
            'codePostal' => $this->codePostal,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'description' => $this->description,
            'imageCouverture' => $this->imageCouverture,
            'imageBanniere' => $this->imageBanniere,
            'status' => $this->status->value,
            'date' => $this->formatDateRange(),
        ];
    }

    public function formatDateRange(): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);

        if ($this->endDate === null || $this->endDate->format('Y-m-d') === $this->startDate->format('Y-m-d')) {
            return $formatter->format($this->startDate);
        }

        return sprintf('%s → %s', $formatter->format($this->startDate), $formatter->format($this->endDate));
    }
}
