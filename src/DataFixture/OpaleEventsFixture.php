<?php

namespace App\DataFixture;

/*
OpaleEventsFixture

QUOI : Jeu statique de 50 événements factices sur la Côte d’Opale avec géolocalisation par ville.

COMMENT : Tableaux compacts `EVENTS` + correspondance `CITY_COORDS` ; méthode `all()` normalise les champs attendus par `SeedEventsCommand`.

OÙ : Importé uniquement par la commande de seed.

POURQUOI : Alimenter démos et tests d’intégration sans dépendre d’une API externe.
*/

final class OpaleEventsFixture
{
    private const CITY_COORDS = [
        'Boulogne-sur-Mer' => ['lat' => 50.7264, 'lng' => 1.6147, 'cp' => '62200'],
        'Calais' => ['lat' => 50.9513, 'lng' => 1.8587, 'cp' => '62100'],
        'Le Touquet' => ['lat' => 50.5184, 'lng' => 1.5797, 'cp' => '62520'],
        'Wimereux' => ['lat' => 50.7641, 'lng' => 1.6131, 'cp' => '62930'],
        'Wissant' => ['lat' => 50.8849, 'lng' => 1.6655, 'cp' => '62179'],
        'Étaples' => ['lat' => 50.5152, 'lng' => 1.6354, 'cp' => '62630'],
        'Berck' => ['lat' => 50.4079, 'lng' => 1.5723, 'cp' => '62600'],
        'Hardelot' => ['lat' => 50.6362, 'lng' => 1.5871, 'cp' => '62152'],
        'Audinghen' => ['lat' => 50.8625, 'lng' => 1.5897, 'cp' => '62179'],
        'Audresselles' => ['lat' => 50.8261, 'lng' => 1.5945, 'cp' => '62164'],
        'Escalles' => ['lat' => 50.9183, 'lng' => 1.7032, 'cp' => '62179'],
    ];

    private const EVENTS = [
        ['Fête de la Musique', 'Boulogne-sur-Mer', 'Musique', '2026-06-21', '18:00', '00:00', null, 'Plus de 30 groupes locaux dans toutes les rues du centre. Pop, rock, électro, jazz : il y en a pour tous les goûts. Programme complet à l\'Office du Tourisme.'],
        ['Concert d\'orgue à la Basilique', 'Boulogne-sur-Mer', 'Musique', '2026-06-15', '20:30', '22:00', '8€', 'Récital exceptionnel sur le grand orgue Cavaillé-Coll de la Basilique Notre-Dame. Programme Bach, Widor, Vierne.'],
        ['Festival Côte d\'Opale en Jazz', 'Boulogne-sur-Mer', 'Festival', '2026-06-13', '17:00', '23:00', '25€', 'Deux jours de jazz en plein air sur le port. 8 groupes internationaux, scène ouverte le dimanche soir. Restauration food-trucks.', '2026-06-14'],
        ['Concert de Jazz sur la Digue', 'Wimereux', 'Musique', '2026-06-21', '19:30', '22:30', null, 'Profitez d\'un coucher de soleil en musique sur la digue de Wimereux. Trois groupes locaux se succèdent dans une ambiance détendue, face à la mer.'],
        ['Brocante du centre-ville', 'Calais', 'Brocante', '2026-06-22', '09:00', '18:00', null, 'Plus de 200 exposants attendus ce dimanche pour chiner sur la Place d\'Armes. Restauration sur place, animations pour enfants l\'après-midi.'],
        ['Initiation Char à voile', 'Boulogne-sur-Mer', 'Sport', '2026-06-25', '14:00', '17:00', '35€/personne', 'Découvrez les sensations de la glisse sur le sable. Encadrement par moniteurs diplômés, matériel fourni. À partir de 14 ans.'],
        ['Vide-grenier du Vieux Boulogne', 'Boulogne-sur-Mer', 'Brocante', '2026-06-27', '08:00', '17:00', null, 'Vide-grenier traditionnel dans les ruelles de la haute-ville. 80 exposants, mix de particuliers et professionnels. Buvette associative.'],
        ['Marché des Producteurs', 'Wissant', 'Marché', '2026-06-28', '09:00', '13:00', null, 'Marché matinal de producteurs locaux : maraîchers, fromagers, pêcheurs, brasseurs artisanaux. Sur la place du village face à l\'église.'],
        ['Exposition Peintres de la Côte', 'Le Touquet', 'Culture', '2026-06-01', '10:00', '18:00', '6€', 'Une rétrospective des plus beaux paysages marins et romantiques peints par les artistes de la Côte d\'Opale, de 1880 à nos jours.', '2026-06-30'],
        ['Conférence "Naufrages de l\'Opale"', 'Audinghen', 'Conférence', '2026-06-18', '19:00', '20:30', null, 'L\'historien Jacques Marchand raconte les grands naufrages survenus au Cap Gris-Nez. Avec projection d\'archives. Salle des fêtes.'],

        ['Concert acoustique sur la plage', 'Berck', 'Musique', '2026-07-04', '20:00', '22:30', null, 'Sunset session au bord de l\'eau : 4 artistes acoustiques se succèdent. Apportez votre serviette ! Restauration sur place.'],
        ['Régate des Voiles d\'Opale', 'Wimereux', 'Sport', '2026-07-05', '10:00', '17:00', null, 'Compétition de voile traditionnelle pour clubs et particuliers, départ et arrivée sur la plage. Ambiance familiale, animations à terre.'],
        ['Marché aux poissons matinal', 'Boulogne-sur-Mer', 'Marché', '2026-07-06', '06:00', '11:00', null, 'Sur le quai Gambetta dès l\'aube, les pêcheurs vendent leur production de la nuit. Soles, turbots, lieus, harengs : le top du frais.'],
        ['Initiation Kitesurf', 'Wissant', 'Sport', '2026-07-08', '10:00', '16:00', '85€', 'Stage d\'une journée pour découvrir le kitesurf sur le spot mythique de Wissant. Matériel inclus. À partir de 16 ans, savoir nager obligatoire.'],
        ['Festival des Voiles Latines', 'Étaples', 'Festival', '2026-07-10', '10:00', '22:00', null, 'Trois jours de fête maritime : défilé de vieux gréements, démos de matelotage, concerts shanty, dégustations.', '2026-07-12'],
        ['Brocante des Antiquaires', 'Le Touquet', 'Brocante', '2026-07-12', '09:00', '19:00', null, 'Brocante haut-de-gamme avec 60 antiquaires professionnels. Mobilier, bijoux, tableaux anciens, objets de collection. Sous chapiteau.'],
        ['Bal populaire et feu d\'artifice', 'Calais', 'Festival', '2026-07-14', '21:00', '00:30', null, 'Bal traditionnel sur la Place d\'Armes suivi du grand feu d\'artifice tiré depuis le port. Buvettes associatives. Spectacle visible depuis toute la baie.'],
        ['Sortie nature Cap Gris-Nez', 'Audinghen', 'Découverte', '2026-07-15', '14:00', '17:00', '12€', 'Balade commentée sur les falaises avec un guide naturaliste : géologie, flore, oiseaux marins. 5 km, accessible aux enfants à partir de 8 ans.'],
        ['Festival International de Cerf-Volant', 'Berck', 'Festival', '2026-07-18', '10:00', '20:00', null, 'L\'un des plus grands rassemblements de cerf-volants au monde. 500 cerfs-volants géants dans le ciel, démos, ateliers enfants.', '2026-07-26'],
        ['Atelier poterie maritime', 'Wissant', 'Atelier', '2026-07-22', '14:00', '17:00', '40€', 'Initiation au tournage de la terre dans un atelier face à la mer. Repartez avec votre création (cuisson différée). Tabliers fournis.'],
        ['Spectacle de marionnettes', 'Calais', 'Famille', '2026-07-24', '15:00', '16:00', '5€', 'Spectacle "Les pirates du Pas-de-Calais" par la compagnie Petites Marionnettes. Adapté aux enfants de 3 à 10 ans. En extérieur si beau temps.'],
        ['Foire aux moules-frites', 'Boulogne-sur-Mer', 'Gastronomie', '2026-07-26', '11:00', '23:00', null, 'Grande foire annuelle sur le port. 15 marmites géantes, vins blancs locaux, ambiance guinguette. Tarif moules-frites à partir de 14€.'],
        ['Nuit des Étoiles', 'Wissant', 'Découverte', '2026-07-28', '21:30', '23:30', null, 'Observation du ciel d\'été sur la plage avec les astronomes amateurs du Cap. Apportez chaise et couverture. Annulé si nuageux.'],
        ['Visite nocturne du phare', 'Calais', 'Culture', '2026-07-30', '21:00', '23:00', '8€', 'Découvrez le phare emblématique de Calais à la nuit tombée. Vue panoramique sur la baie, anecdotes par un ancien gardien. Réservation conseillée.'],
        ['Concert classique au Casino', 'Le Touquet', 'Musique', '2026-07-31', '20:30', '22:30', '22€', 'Trio à cordes de l\'Opéra de Lille interprète Schubert, Mendelssohn, Brahms. Cadre prestigieux du salon Empire du Casino Barrière.'],
        ['Tournoi de Beach-Volley', 'Berck', 'Sport', '2026-07-14', '09:00', '18:00', '15€/équipe', 'Tournoi amateur sur sable, en équipes de 2 ou 4. Tirage au sort le matin, finales à 17h. Buvette et restauration sur place. Inscription obligatoire.'],

        ['Marché bio des producteurs', 'Wimereux', 'Marché', '2026-08-02', '09:00', '13:00', null, 'Marché bio hebdomadaire : maraîchers locaux, boulangerie au levain, fromages fermiers, miel et confitures, plantes aromatiques.'],
        ['Triathlon de Wissant', 'Wissant', 'Sport', '2026-08-03', '08:00', '14:00', '45€', '500m natation, 20km vélo, 5km course à pied. Sur les paysages du Cap Gris-Nez. Format M et XS, ouvert aux licenciés et non-licenciés.'],
        ['Atelier cuisine produits de la mer', 'Le Touquet', 'Atelier', '2026-08-05', '10:00', '14:00', '65€', 'Apprenez à cuisiner poissons, coquillages et crustacés avec un chef étoilé. Cours collectif (8 personnes max), tablier offert, dégustation incluse.'],
        ['Dégustation huîtres et fruits de mer', 'Wissant', 'Gastronomie', '2026-08-07', '12:00', '15:00', '18€', 'Plateaux de fruits de mer pêchés du jour : huîtres de Cancale, bulots, crevettes grises, tourteau. Vin blanc local en accompagnement.'],
        ['Marathon de la Côte d\'Opale', 'Hardelot', 'Sport', '2026-08-08', '07:00', '13:00', '50€', '42 km mythiques entre forêt, dunes et bord de mer, de Hardelot à Berck. Ravitaillements tous les 5 km, classement officiel FFA.'],
        ['Festival des Saveurs Locales', 'Berck', 'Gastronomie', '2026-08-10', '11:00', '22:00', null, 'Trois jours de gastronomie locale : producteurs, brasseurs, chocolatiers, fromagers. Démos culinaires, ateliers enfants, food-trucks.', '2026-08-12'],
        ['Visite Nausicaá nocturne', 'Boulogne-sur-Mer', 'Famille', '2026-08-12', '20:00', '23:00', '28€', 'Visite exceptionnelle du Centre National de la Mer après fermeture. Ambiance lumière tamisée, accès au bassin des requins. Réservation obligatoire.'],
        ['Chasse au trésor sur la plage', 'Wimereux', 'Famille', '2026-08-17', '14:00', '17:00', '6€', 'Aventure ludique pour enfants 6-12 ans : énigmes, cartes au trésor, vrais coquillages à collecter. Goûter de fin de chasse offert.'],
        ['Soirée Reggae sur la Plage', 'Berck', 'Musique', '2026-08-20', '19:00', '01:00', null, 'Sound system jamaïcain face à la mer, 3 DJ et 2 groupes live. Ambiance roots, food caribéen, mocktails. Petits comme grands.'],
        ['Course de paddle', 'Wimereux', 'Sport', '2026-08-22', '10:00', '15:00', '20€', 'Compétition amateur de stand-up paddle : sprint 200m + tour de la baie 5km. Trois catégories d\'âge, planches en location possible.'],
        ['Atelier photographie marine', 'Audinghen', 'Atelier', '2026-08-28', '14:00', '18:00', '55€', 'Stage photo paysage sur les falaises du Cap Gris-Nez avec un photographe pro. Théorie + pratique. Apportez votre appareil (reflex ou hybride).'],
        ['Foire aux livres anciens', 'Calais', 'Brocante', '2026-08-30', '10:00', '18:00', null, 'Bibliophiles et lecteurs : 30 libraires spécialisés en livres d\'occasion, BD, vieilles éditions, cartes postales anciennes.'],
        ['Festival d\'art urbain', 'Boulogne-sur-Mer', 'Festival', '2026-08-14', '10:00', '22:00', null, 'Street art en live sur les murs du port : 20 artistes internationaux, performances, ateliers graff, DJ sets. Parcours libre.', '2026-08-16'],
        ['Sortie observation oiseaux', 'Le Touquet', 'Découverte', '2026-08-25', '07:00', '11:00', '8€', 'Avec un ornithologue de la LPO : observation des migrateurs dans la baie de la Canche. Jumelles fournies, longue-vue mise à disposition.'],
        ['Balade littoral GR120', 'Audresselles', 'Découverte', '2026-08-09', '09:30', '15:00', null, 'Randonnée de 12 km sur le sentier des douaniers entre Audresselles et Cap Gris-Nez. Niveau modéré. Pique-nique tiré du sac.'],
        ['Observation phoques baie d\'Authie', 'Berck', 'Découverte', '2026-08-15', '13:00', '17:00', '15€', 'Approche encadrée des phoques de la baie d\'Authie. Botte ou chaussures de marche obligatoires. Jumelles recommandées. 3km de marche douce.'],
        ['Cap Blanc-Nez : sortie géologique', 'Escalles', 'Découverte', '2026-08-21', '14:00', '17:00', '10€', 'Comprendre la formation des falaises de craie avec un géologue. Découverte de fossiles, démos sur le terrain. Sortie famille à partir de 8 ans.'],
        ['Visite guidée des Phares', 'Calais', 'Culture', '2026-08-04', '15:00', '17:00', '7€', 'Découverte commentée du phare de Calais et de son histoire. Montée des 271 marches récompensée par une vue exceptionnelle.'],
        ['Festival du film documentaire', 'Calais', 'Festival', '2026-08-22', '14:00', '23:00', '12€', 'Trois jours de docus engagés sur l\'écologie, le voyage, la société. Débats avec les réalisateurs. Cinéma Alhambra.', '2026-08-24'],
        ['Exposition photo "Marées"', 'Boulogne-sur-Mer', 'Culture', '2026-08-01', '10:00', '19:00', '5€', 'Une trentaine de photographes professionnels capturent les marées de la Côte d\'Opale. Tirages grand format, parcours immersif.', '2026-08-31'],
        ['Initiation kayak en famille', 'Hardelot', 'Famille', '2026-08-11', '14:00', '17:00', '25€/pers', 'Découverte du kayak de mer encadrée par un moniteur diplômé. Adapté aux familles, enfants à partir de 8 ans accompagnés. Gilet et combinaison fournis.'],
        ['Vernissage galerie d\'art', 'Le Touquet', 'Culture', '2026-08-06', '18:30', '21:00', null, 'Inauguration de l\'exposition "Côtes inspirées" de l\'artiste peintre Marie Lefèvre. Cocktail offert, rencontre avec l\'artiste.'],
        ['Apéro-concert au phare', 'Audinghen', 'Musique', '2026-08-18', '18:30', '21:00', '12€', 'Concert intimiste en terrasse du Café du Phare. Duo guitare-voix, vue mer, planches charcuterie locale incluses.'],
        ['Bal populaire du 15 août', 'Étaples', 'Festival', '2026-08-15', '20:00', '01:00', null, 'Bal de l\'Assomption sur le quai. Orchestre rétro, valse, rock, paso doble. Buvette et frites. Ouvert à tous.'],
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(static function (array $row): array {
            $coords = self::CITY_COORDS[$row[1]] ?? null;
            if ($coords === null) {
                throw new \RuntimeException(sprintf('Coords manquantes pour la ville "%s".', $row[1]));
            }

            return [
                'titre' => $row[0],
                'ville' => $row[1],
                'categorie' => $row[2],
                'startDate' => $row[3],
                'startTime' => $row[4],
                'endTime' => $row[5],
                'prix' => $row[6],
                'description' => $row[7],
                'endDate' => $row[8] ?? null,
                'codePostal' => $coords['cp'],
                'latitude' => $coords['lat'],
                'longitude' => $coords['lng'],
                'adresse' => sprintf('%s, %s', $row[1], $coords['cp']),
                'nomOrganisateur' => 'Office du Tourisme ' . $row[1],
                'emailContact' => 'contact-' . strtolower(str_replace([' ', '-'], '', $row[1])) . '@opale-tourisme.fr',
            ];
        }, self::EVENTS);
    }
}
