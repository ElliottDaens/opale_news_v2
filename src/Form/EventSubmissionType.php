<?php

namespace App\Form;

use App\Entity\Event;
use App\Service\GeminiTextService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/*
EventSubmissionType

QUOI : Formulaire Symfony d’`Event` pour la page publique de proposition (champs métier, fichiers non mappés, honeypot).

COMMENT : Contraintes Validator, listes de catégories alignées sur `GeminiTextService::CATEGORIES`, champs cachés lat/lng, honeypot non mappé.

OÙ : Utilisé par `EventSubmissionController` avec token CSRF dédié.

POURQUOI : Valider côté serveur tout le périmètre fonctionnel sans exposer les champs fichiers dans l’entité avant traitement.
*/

final class EventSubmissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre de l\'événement',
                'attr' => ['maxlength' => 200, 'placeholder' => 'Ex : Concert de Jazz sur la Digue'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 200)],
            ])
            ->add('nomOrganisateur', TextType::class, [
                'label' => 'Nom ou organisme',
                'attr' => ['maxlength' => 150, 'placeholder' => 'Office du Tourisme, association, particulier…'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 150)],
            ])
            ->add('emailContact', EmailType::class, [
                'label' => 'Email de contact',
                'attr' => ['placeholder' => 'contact@exemple.fr'],
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('prix', TextType::class, [
                'label' => 'Prix (0 ou vide = gratuit)',
                'required' => false,
                'attr' => [
                    'type' => 'number',
                    'min' => '0',
                    'step' => '0.01',
                    'placeholder' => '0',
                ],
                'constraints' => [
                    new Assert\Regex(
                        pattern: '/^\d+([.,]\d{1,2})?$/',
                        message: 'Montant invalide (ex : 10 ou 10,50).',
                    ),
                ],
            ])
            ->add('categorie', ChoiceType::class, [
                'label' => 'Catégorie',
                'choices' => array_combine(GeminiTextService::CATEGORIES, GeminiTextService::CATEGORIES),
                'placeholder' => '— Choisir une catégorie —',
                'constraints' => [new Assert\NotBlank(), new Assert\Choice(choices: GeminiTextService::CATEGORIES)],
            ])

            ->add('startDate', DateType::class, [
                'label' => 'Date de début',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Date de fin (optionnelle)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('startTime', TimeType::class, [
                'label' => 'Heure de début',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('endTime', TimeType::class, [
                'label' => 'Heure de fin',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])

            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'Commencez à taper, l\'auto-complétion vous propose des résultats…',
                    'data-places-autocomplete' => 'true',
                    'autocomplete' => 'off',
                ],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'attr' => ['maxlength' => 120],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)],
            ])
            ->add('codePostal', TextType::class, [
                'label' => 'Code postal',
                'attr' => ['maxlength' => 5, 'pattern' => '\d{5}', 'inputmode' => 'numeric'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(pattern: '/^\d{5}$/', message: 'Code postal invalide (5 chiffres attendus).'),
                ],
            ])
            ->add('latitude', HiddenType::class, ['required' => false])
            ->add('longitude', HiddenType::class, ['required' => false])

            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'attr' => ['rows' => 6, 'maxlength' => 2000, 'placeholder' => 'Décrivez l\'événement, ce qui le rend unique, à qui il s\'adresse…'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 30, max: 2000, minMessage: 'Au moins 30 caractères, merci de détailler un peu.'),
                ],
            ])

            ->add('imageCouverture', FileType::class, [
                'label' => 'Image de couverture (ratio 4:3)',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '8M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'JPEG, PNG ou WebP uniquement, 8 Mo max.',
                    ),
                ],
            ])
            ->add('imageBanniere', FileType::class, [
                'label' => 'Image de bannière (ratio 16:6)',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '8M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'JPEG, PNG ou WebP uniquement, 8 Mo max.',
                    ),
                ],
            ])

            ->add('website', TextType::class, [
                'mapped'   => false,
                'required' => false,
                'label' => false,
                'attr' => [
                    'autocomplete' => 'off',
                    'tabindex' => '-1',
                    'aria-hidden' => 'true',
                ],
                'row_attr' => ['class' => 'honeypot-field'],
            ]);

        // Transformer : "10€" → "10" à l'affichage, "10" → setPrix gère le formatage
        $builder->get('prix')->addModelTransformer(new CallbackTransformer(
            static fn (?string $v): ?string => $v !== null ? rtrim(trim($v), '€') : null,
            static fn (?string $v): ?string => $v,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Event::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'event_submission',
        ]);
    }
}
