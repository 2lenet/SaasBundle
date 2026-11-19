# LleSaasBundle

Bundle Symfony « helper » pour lancer rapidement un SaaS 2le : comptes clients (`Customer`, tenant),
utilisateurs rattachés, abonnements et synchronisation avec **Stripe** (Checkout, Customer Portal, webhooks),
avec une journalisation complète des échanges Stripe.

Le métier propre à chaque SaaS reste dans l'application consommatrice.

> Version initiale, pas encore publiée : voir `.claude/plan.md` pour l'avancement.

## Prérequis

- PHP 8.4+
- Symfony 8
- Doctrine ORM 3

## Installation

```bash
composer require 2lenet/saas-bundle
```

```php
// config/bundles.php
return [
    // ...
    Lle\SaasBundle\LleSaasBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/lle_saas.yaml
lle_saas:
    user_class: App\Entity\User                # entité User de l'application (utilisateur Connect)
    routes:                                    # pages de l'application où Stripe renvoie le client
        checkout_success: app_billing_success  # reçoit ?session_id=cs_...
        checkout_cancel: app_pricing
        billing_portal_return: app_account
    # customer_class: App\Entity\Customer      # optionnel : remplace l'entité Customer du bundle
    stripe:
        secret_key: '%env(STRIPE_SECRET_KEY)%'
        webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%'
        app_id: 'rgaa_checker'                   # identifiant de l'application dans le compte Stripe (voir ci-dessous)
        # api_version: '2026-10-29.clover'       # optionnel : fige la version de l'API Stripe
```

Le bundle déclare lui-même les `resolve_target_entities` Doctrine (`SaasUserInterface` → `user_class`,
`CustomerInterface` → `customer_class`). Les tables sont préfixées `lle_saas_` : générer la migration avec
`make:migration` après l'installation.

## Modèle

| Entité | Table | Rôle |
|---|---|---|
| `Customer` | `lle_saas_customer` | Compte facturable et **tenant** (`getTenantId()` = id du customer) |
| `Plan` | `lle_saas_plan` | Offre, copie locale d'un prix Stripe (`code` = `lookup_key` du prix) |
| `Subscription` | `lle_saas_subscription` | Abonnement d'un customer, tenu à jour par les webhooks |
| `StripeEvent` | `lle_saas_stripe_event` | Journal de **tous** les webhooks reçus (et idempotence) |
| `StripeApiCall` | `lle_saas_stripe_api_call` | Journal de **tous** les appels à l'API Stripe |

Les statuts d'abonnement donnant accès au service sont `active`, `trialing` et `past_due`
(l'accès est conservé pendant les relances de paiement Stripe).

### Utilisateur

L'entité User de l'application (celle issue de Connect) implémente `SaasUserInterface` via `SaasUserTrait`.
`getTenantId()` a la même signature que les contrats multi-tenant de HermesBundle et ConfigBundle :

```php
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Lle\SaasBundle\Entity\SaasUserTrait;

class User implements UserInterface, SaasUserInterface, MultiTenantInterface /* Hermes */, TenantInterface /* Config */
{
    use SaasUserTrait; // relation customer + rôle dans le compte (owner / member)
}
```

Le customer de l'utilisateur connecté s'obtient avec `Lle\SaasBundle\Service\TenantProvider`
(`getCurrentCustomer()`, `getTenantId()`).

### Remplacer l'entité Customer

Pour ajouter des champs au customer, créer une entité qui implémente `CustomerInterface` avec `CustomerTrait`,
appeler `initializeCustomer()` dans son constructeur, et la déclarer dans `lle_saas.customer_class` :
l'entité `Customer` du bundle est alors retirée du mapping Doctrine.

```php
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customer')]
class Customer implements CustomerInterface
{
    use CustomerTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    protected ?int $id = null;

    public function __construct()
    {
        $this->initializeCustomer();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
```

## Stripe

Tous les appels du bundle à Stripe passent par `Lle\SaasBundle\Stripe\StripeGatewayInterface`.

### Compte Stripe partagé

Un compte Stripe peut servir à plusieurs SaaS 2le. Chaque application a son `lle_saas.stripe.app_id` (lettres,
chiffres, `_`, `-`), écrit en métadonnée **`lle_saas_app`** sur tout ce que le bundle crée dans Stripe (client,
session Checkout, abonnement). L'application ne prend en charge que ses objets :

- plans : seuls les prix dont le **produit** (ou le prix) porte `lle_saas_app` = `app_id` sont synchronisés ;
- webhooks : un abonnement, une session, un client ou une facture d'une autre application est journalisé `ignored`
  (réponse 200, pas de nouvel envoi) ;
- `lle:saas:reconcile` ignore les abonnements des autres applications.

Chaque application déclare son propre endpoint webhook dans Stripe (il reçoit tous les événements du compte).

### Offres

Les offres se créent **dans Stripe** : un produit portant la métadonnée **`lle_saas_app`** = `app_id`, et un prix
récurrent portant un **`lookup_key`**, qui devient le `code` du plan côté application (métadonnée optionnelle
`position` sur le prix pour l'ordre d'affichage).

```bash
bin/console lle:saas:plan:sync
```

- prix sans `lookup_key` : ignoré (signalé par la commande) ;
- `lookup_key` déplacé sur un nouveau prix : le plan existant est mis à jour ; les abonnements restés sur l'ancien
  prix gardent leur plan (le prix est mémorisé sur l'abonnement) ;
- prix ou produit archivé : le plan passe inactif.

### Clients

`Lle\SaasBundle\Service\CustomerSynchronizer::synchronize($customer)` crée le client Stripe ou le met à jour. Le client
Stripe porte les métadonnées `lle_saas_customer_id` et `lle_saas_app`.

La création résiste à une double soumission : la ligne du client est verrouillée le temps de l'appel Stripe, et la
clé d'idempotence dérive de l'id local (Stripe renvoie le même client pendant 24 h). L'id Stripe est écrit par une
mise à jour ciblée : les modifications en attente de l'application ne sont **pas** flushées.

### Monitoring des appels

Chaque appel est journalisé dans `lle_saas_stripe_api_call` (opération, objet, client, clé d'idempotence,
`Request-Id` Stripe, statut HTTP, erreur, durée, paramètres envoyés) et loggé sur le canal `saas_stripe`.
Les créations portent une clé d'idempotence, et le SDK rejoue jusqu'à 2 fois les erreurs réseau.

Pour isoler ces logs (avec `symfony/monolog-bundle`) :

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['saas_stripe']
    handlers:
        saas_stripe:
            type: rotating_file
            path: '%kernel.logs_dir%/saas_stripe.log'
            channels: ['saas_stripe']
```

Le journal est écrit par insertion directe (pas de `flush()` de l'unité de travail de l'application) ; s'il
échoue, l'appel Stripe n'est pas interrompu et un avertissement est loggé.

## Abonnement et espace client

```yaml
# config/routes/lle_saas.yaml
lle_saas:
    resource: '@LleSaasBundle/Resources/config/routes.yaml'
    prefix: /saas
```

| Route | Méthode | Effet |
|---|---|---|
| `lle_saas_checkout` (`/saas/checkout/{code}`) | POST | Redirige vers Stripe Checkout pour souscrire au plan `code` |
| `lle_saas_billing_portal` (`/saas/billing-portal`) | POST | Redirige vers l'espace client Stripe (carte, factures, changement d'offre, résiliation) |

```twig
<form method="post" action="{{ path('lle_saas_checkout', {code: plan.code}) }}">
    <input type="hidden" name="_token" value="{{ csrf_token('lle_saas_checkout') }}">
    <button>{{ 'saas.checkout.subscribe'|trans }}</button>
</form>

<form method="post" action="{{ path('lle_saas_billing_portal') }}">
    <input type="hidden" name="_token" value="{{ csrf_token('lle_saas_billing_portal') }}">
    <button>{{ 'saas.billing_portal.open'|trans }}</button>
</form>
```

Accès (les deux conditions) :

- le rôle `ROLE_SAAS_CUSTOMER_ACTION_CHECKOUT` / `ROLE_SAAS_CUSTOMER_ACTION_BILLINGPORTAL`, déclaré par
  `lle:credential:warmup` (section `SAAS`) et attribué aux groupes dans CredentialBundle — jamais dans `role_hierarchy`.
  La matrice traduit les libellés dans le domaine `messages` : clés à créer dans Loco par l'application,
  `credential.saas.customer_action_checkout` (« Client — Souscrire à une offre ») et
  `credential.saas.customer_action_billingportal` (« Client — Ouvrir l'espace de facturation Stripe ») ;
- être **propriétaire** (`CustomerRole::Owner`) du compte : voter `LLE_SAAS_MANAGE_BILLING`
  (`Lle\SaasBundle\Security\CustomerBillingVoter`), réutilisable dans l'application.

Réponses : plan inconnu ou inactif → 404 ; client ayant déjà un abonnement en cours, en local **ou dans Stripe**
(actif, en attente de paiement, impayé, en pause) → 409 (passer par l'espace client) ;
espace client d'un client inconnu de Stripe → 404. Le client Stripe est créé au premier checkout.
L'abonnement local n'est **pas** créé au retour de Checkout : il arrive par les webhooks (ci-dessous).

Deux onglets de checkout ouverts avant tout paiement restent possibles : activer aussi, dans Stripe (Paramètres →
Checkout), **« Limiter les clients à un abonnement »**.

La protection CSRF repose sur `symfony/security-csrf` (dépendance du bundle) et `framework.csrf_protection`. Un jeton
absent ou faux est refusé par le pare-feu (401, ou le point d'entrée configuré).

## Webhooks

Endpoint : `POST /saas/webhook` (route `lle_saas_webhook`, avec le préfixe choisi à l'import des routes).
Il est **public** : la requête est authentifiée par sa signature (`lle_saas.stripe.webhook_secret`).

```yaml
# config/packages/security.yaml
security:
    firewalls:
        stripe_webhook:
            pattern: ^/saas/webhook$
            stateless: true
            security: false
```

Dans Stripe (Développeurs → Webhooks), créer l'endpoint et sélectionner au minimum :
`checkout.session.completed`, `customer.subscription.created|updated|deleted|paused|resumed`,
`customer.updated`, `customer.deleted`, `invoice.paid`, `invoice.payment_failed`, `product.*`, `price.*`
(liste dans `Lle\SaasBundle\Webhook\StripeEventType`).

En développement, avec la CLI Stripe :

```bash
stripe listen --forward-to http://127.0.0.1:8000/saas/webhook
# copier le "webhook signing secret" (whsec_...) dans STRIPE_WEBHOOK_SECRET
```

Traitement (`WebhookProcessor`) :

- tout événement signé est journalisé dans `StripeEvent` (payload, type, objet, client Stripe, statut, tentatives,
  erreur, durée), même s'il n'est pas géré (`ignored`) ;
- un événement déjà `processed` / `ignored` n'est pas retraité (Stripe peut livrer deux fois) ;
- l'événement est **réservé** (`processing`) par une mise à jour conditionnelle : une livraison concurrente du même
  événement reçoit **409** et sera renvoyée par Stripe (une réservation de plus de 10 minutes est considérée
  abandonnée) ;
- les handlers qui le supportent tournent **dans une transaction** : une exception annule leur travail, l'événement
  passe `failed` et l'endpoint répond **500** pour que Stripe le renvoie ; un conflit avec une livraison concurrente
  (même abonnement : verrou optimiste, contrainte d'unicité) relance une fois le traitement ;
- un objet d'une autre application du compte Stripe → `ignored` ;
- réponses : 200 `{"status": "processed|ignored"}`, 400 `{"error": "invalid_signature"}` (le détail est dans le log
  `saas_stripe`, jamais dans la réponse), 409 `{"status": "processing"}`, 500 `{"status": "failed"}`.

Handlers fournis :

| Événements | Effet |
|---|---|
| `customer.subscription.*`, `checkout.session.completed` | abonnement relu dans Stripe (ordre des événements non garanti) puis copié en local |
| `customer.updated` / `customer.deleted` | email de facturation relu dans Stripe puis recopié / client Stripe délié |
| `product.*`, `price.*` | resynchronisation des plans |
| `invoice.paid`, `invoice.payment_failed` | événements `InvoicePaidEvent` / `PaymentFailedEvent` (l'abonnement est synchronisé d'abord s'il n'est pas encore arrivé) |

Un abonnement de cette application (`lle_saas_app`) dont le client ne correspond à aucun Customer local (ni par
`stripeCustomerId`, ni par la métadonnée `lle_saas_customer_id` posée par le checkout) fait échouer l'événement : il
reste rejouable. Le journal des appels à l'API Stripe faits pendant le traitement est écrit après la transaction
(conservé même en cas d'annulation).

### Réagir dans l'application

```php
use Lle\SaasBundle\Event\SubscriptionActivatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class WelcomeOnSubscription
{
    public function __invoke(SubscriptionActivatedEvent $event): void
    {
        $customer = $event->getSubscription()->getCustomer();
        // ...
    }
}
```

| Événement | Quand |
|---|---|
| `SubscriptionActivatedEvent` | l'abonnement donne désormais accès (`active`, `trialing`, `past_due`) |
| `SubscriptionUpdatedEvent` | un abonnement existant a changé (statut, plan, quantité, période…) ; `getPreviousStatus()`, `getPreviousPlan()`, `hasPlanChanged()` |
| `SubscriptionCanceledEvent` | l'abonnement passe `canceled` |
| `InvoicePaidEvent` / `PaymentFailedEvent` | facture payée / paiement refusé, avec le Customer local et l'abonnement local (null pour une facture hors abonnement) |

Les événements sont déclenchés **après le commit** de la transaction du webhook : un listener n'agit jamais sur des
changements annulés ensuite. Un listener en échec est journalisé (log `saas_stripe` et champ `error` de l'événement,
qui reste `processed`) mais **n'est pas rejoué** : pour un effet de bord qui doit aboutir, passer par Messenger.
Un statut Stripe inconnu de cette version du bundle est journalisé et ne change rien (un nouvel abonnement reste
`incomplete`, sans accès). La résiliation programmée à une date (`cancel_at`) est conservée sur l'abonnement.
Un abonnement vu pour la première fois déjà terminé (historique importé par `reconcile`) ne déclenche aucun événement.

Pour gérer d'autres événements Stripe, implémenter `Lle\SaasBundle\Webhook\StripeEventHandlerInterface` : le service
est enregistré par autoconfiguration.

### Rejouer

```bash
bin/console lle:saas:webhook:replay --failed            # les événements en échec, plus anciens d'abord
bin/console lle:saas:webhook:replay evt_1Q...           # un événement, quel que soit son statut
```

## Réserver le service aux abonnés

`SubscriptionChecker` répond pour le Customer de l'utilisateur connecté (ou celui passé en argument). Donnent accès
les abonnements `active`, `trialing` et `past_due` (le temps des relances de paiement).

```php
$checker->hasActiveSubscription();       // un abonnement donnant accès
$checker->hasPlan('pro');                // ... sur ce plan (code = lookup_key Stripe)
$checker->getActiveSubscription();       // le plus récent, ou null
```

Voter `LLE_SAAS_SUBSCRIBED` (`Lle\SaasBundle\Security\SubscriptionVoter`), règle métier qui s'ajoute aux rôles
CredentialBundle :

```php
#[IsGranted(SubscriptionVoter::SUBSCRIBED)]          // abonné
#[IsGranted(SubscriptionVoter::SUBSCRIBED, 'pro')]   // abonné au plan "pro"
```

Avec TwigBundle : `saas_has_subscription()`, `saas_has_plan('pro')`, `saas_subscription()`.

## Écrans d'administration (Crudit)

Avec [CruditBundle](https://github.com/2lenet/CruditBundle) activé dans le kernel, le bundle ajoute quatre écrans en
**consultation seule** (liste filtrable, fiche, export), et une section « SaaS » au menu (`ROLE_SAAS_MENU`) :

| Écran | Liste (à lier dans un menu) | Rôles |
|---|---|---|
| Abonnements | `lle_saas_crudit_subscription_index` | `ROLE_SAAS_SUBSCRIPTION_INDEX`, `_SHOW`, `_EXPORT` |
| Plans | `lle_saas_crudit_plan_index` | `ROLE_SAAS_PLAN_INDEX`, `_SHOW`, `_EXPORT` |
| Webhooks reçus | `lle_saas_crudit_stripeevent_index` | `ROLE_SAAS_STRIPEEVENT_INDEX`, `_SHOW`, `_EXPORT` |
| Appels à l'API Stripe | `lle_saas_crudit_stripeapicall_index` | `ROLE_SAAS_STRIPEAPICALL_INDEX`, `_SHOW`, `_EXPORT` |

Les rôles sont déclarés par `lle:credential:warmup` (CruditPlatformBundle) et s'attribuent dans la matrice — jamais
dans `role_hierarchy`. Les données venant de Stripe (ou étant des journaux), les routes de création, modification,
suppression, édition en place, tri, workflow et autocomplétion **n'existent pas**, quels que soient les rôles.
Les routes viennent de l'import de `@LleSaasBundle/Resources/config/routes.yaml` : rien à ajouter. Le tri n'est
accepté que sur les colonnes de la liste (ASC / DESC) et une page compte au plus 200 lignes.

Traductions (titres, colonnes, filtres, statuts, menu) livrées par le bundle dans le domaine **`LleSaasBundle`**
(`src/Resources/translations/LleSaasBundle.{fr,en}.yaml`). Une application peut surcharger une clé dans son propre
catalogue `LleSaasBundle` (fichier `translations/LleSaasBundle.fr.yaml` ou Loco).

Intégrations optionnelles (Crudit, CredentialBundle, Twig) : enregistrées seulement quand leur bundle est **activé**
dans `config/bundles.php` (paquet installé ne suffit pas).

## Exploitation

```bash
# synchronisation des offres (aussi faite par les webhooks product.* / price.*)
bin/console lle:saas:plan:sync

# abonnements locaux comparés à Stripe : échoue (code 1) tant qu'un écart subsiste → utilisable en cron de supervision
bin/console lle:saas:reconcile           # rapport
bin/console lle:saas:reconcile --fix     # synchronise les abonnements manquants ou différents

# rétention des journaux StripeEvent / StripeApiCall (90 jours par défaut, webhooks en échec conservés)
bin/console lle:saas:monitor:purge --days=90 [--include-failed]

# webhooks en échec
bin/console lle:saas:webhook:replay --failed
```

Crons suggérés : `lle:saas:reconcile` quotidien (alerte si échec), `lle:saas:monitor:purge` hebdomadaire.
`reconcile` ignore les abonnements Stripe des autres applications (compte partagé) et les abonnements déjà terminés
absents en local (historique) ; il compare statut, prix de l'abonnement et résiliation en fin de période ; un
abonnement local en cours inconnu de Stripe est signalé, jamais modifié ; une erreur sur un abonnement n'arrête pas
les suivants.

### Mise en production

1. Variables `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` (secret de l'endpoint **de production**, différent de celui
   de `stripe listen`) ; `app_id` choisi une fois pour toutes (il est écrit dans les métadonnées Stripe).
2. `doctrine:migrations:migrate`, puis `lle:credential:warmup` (rôles `ROLE_SAAS_*`) et attribution des rôles aux
   groupes.
3. Dans Stripe : produits avec la métadonnée `lle_saas_app`, prix avec `lookup_key`, puis `lle:saas:plan:sync`.
4. Dans Stripe : endpoint webhook (événements ci-dessus), **espace client configuré** (Paramètres → Facturation →
   Espace client : sans configuration, l'ouverture de l'espace client échoue), « Limiter les clients à un
   abonnement » dans les paramètres de Checkout.
5. Crons `reconcile` et `monitor:purge`.

### Supprimer un client

Un Customer qui a des abonnements ne peut pas être supprimé (clé étrangère sans cascade) : l'historique de facturation
est conservé et rien n'est résilié dans Stripe par une suppression locale. Résilier d'abord dans Stripe (espace client
ou dashboard) ; les utilisateurs du client sont détachés (`SET NULL`) à sa suppression. Les journaux gardent l'id du
client Stripe, sans lien en base.

## Sécurité

- **`framework.trusted_hosts`** (et `trusted_proxies` derrière un proxy) : les URL de retour envoyées à Stripe
  (`success_url`, `cancel_url`, `return_url`) sont construites avec l'hôte de la requête. Sans liste d'hôtes de
  confiance, un en-tête `Host` forgé renverrait le payeur vers un autre domaine.
- **Rôles d'administration** (`ROLE_SAAS_MENU`, `ROLE_SAAS_<ÉCRAN>_*`) : les écrans Crudit montrent les abonnements et
  journaux de **tous** les clients. Ne jamais les attribuer à un groupe d'utilisateurs clients, seulement à l'équipe
  qui exploite le SaaS.
- Endpoint webhook : authentifié par signature uniquement (tolérance de 5 minutes sur l'horodatage) ; garder
  `webhook_secret` hors du dépôt (`%env()%` / secrets Symfony).
- Les actions de facturation exigent le rôle Credential **et** d'être propriétaire du compte client ; elles sont en
  POST avec jeton CSRF.

## Données personnelles (RGPD)

Les journaux contiennent des données personnelles : `StripeEvent.payload` (nom, email, adresse de facturation des
clients et factures Stripe) et `StripeApiCall.requestPayload` (nom et email envoyés à Stripe). À prendre en compte :

- durée de conservation : `lle:saas:monitor:purge` (90 jours par défaut) en cron ; les webhooks en échec sont gardés
  tant que `--include-failed` n'est pas passé ;
- accès : réservé aux rôles d'administration ci-dessus ;
- droit à l'effacement : supprimer le client dans Stripe (Stripe a ses propres obligations de conservation des
  factures), puis purger les journaux ; aucun effacement automatique par client n'est fourni ;
- Stripe est un sous-traitant à déclarer dans le registre de l'application.

## API publique

Utilisables par l'application (stables) : `Contracts\*`, `Entity\*`, `Enum\*`, `Event\*`, `Exception\*`,
`Repository\*`, `Service\SubscriptionChecker`, `CheckoutService`, `BillingPortalService`, `CustomerSynchronizer`,
`TenantProvider`, `Security\SaasRole`, `SubscriptionVoter`, `CustomerBillingVoter`, `Stripe\StripeGatewayInterface`,
`StripeApiCallJournalInterface`, `Webhook\StripeEventHandlerInterface`, `StripeEventType`, les routes, les commandes
et la configuration `lle_saas`.

Le reste (classes marquées `@internal` : handlers, synchroniseurs, contrôleurs, écrans Crudit…) peut changer sans
préavis : ne pas en hériter ni l'appeler directement.

## Développement

```bash
make install   # dépendances (composer via Docker)
make test      # PHPUnit
make lint      # PHP CodeSniffer + PHPStan niveau 8
make format    # correction automatique PHP CodeSniffer
```

Les tests tournent sur SQLite en mémoire. Pour un vrai serveur (la CI le fait sur MySQL 8.4 et PostgreSQL 17 ; PHP
local avec `pdo_pgsql` / `pdo_mysql`) :

```bash
LLE_SAAS_TEST_DATABASE_URL='postgresql://postgres:test@127.0.0.1:5432/lle_saas?serverVersion=17' make test
```

La base désignée est vidée (schéma supprimé puis recréé) à chaque test.
