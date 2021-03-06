<?php

namespace craftnet\cms;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craftnet\errors\LicenseNotFoundException;
use craftnet\Module;
use craftnet\plugins\Plugin;
use LayerShifter\TLDExtract\Extract;
use LayerShifter\TLDExtract\IDN;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\db\Expression;

class CmsLicenseManager extends Component
{
    const EDITION_SOLO = 'solo';
    const EDITION_PRO = 'pro';

    /**
     * @var array Domains that we treat as private, because they are only used for dev/testing/staging purposes
     * @see normalizeDomain()
     */
    public $devDomains = [];

    /**
     * @var array TLDs that we treat as private, because they are only used for dev/testing/staging purposes
     * @see normalizeDomain()
     */
    public $devTlds = [];

    /**
     * @var array Words that can be found in the subdomain that will cause the domain to be treated as private
     * @see normalizeDomain()
     */
    public $devSubdomainWords = [];

    /**
     * Normalizes a license key by trimming whitespace and removing newlines.
     *
     * @param string $key
     *
     * @return string
     * @throws InvalidArgumentException if $key is invalid
     */
    public function normalizeKey(string $key): string
    {
        $normalized = trim(preg_replace('/[\r\n]+/', '', $key));
        if (strlen($normalized) !== 250) {
            throw new InvalidArgumentException('Invalid license key: '.$key);
        }

        return $normalized;
    }

    /**
     * Normalizes a public domain.
     *
     * @param string $url
     *
     * @return string|null
     */
    public function normalizeDomain(string $url)
    {
        $isPunycoded = StringHelper::contains($url, 'xn--', false);

        if ($isPunycoded) {
            $url = (new IDN())->toUTF8($url);
        }

        $result = (new Extract(null, null, Extract::MODE_ALLOW_ICANN))
            ->parse(mb_strtolower($url));

        if (($domain = $result->getRegistrableDomain()) === null) {
            return null;
        }

        // ignore if it's a dev domain
        if (
            in_array($domain, $this->devDomains, true) ||
            in_array($result->getFullHost(), $this->devDomains, true)
        ) {
            return null;
        }

        // ignore if it's a dev TLD
        if (in_array($result->getSuffix(), $this->devTlds, true)) {
            return null;
        }

        // ignore if it's a nonstandard port
        $port = parse_url($url, PHP_URL_PORT);
        if ($port && $port != 80 && $port != 443) {
            return null;
        }

        // Check if any of the subdomains sound dev-y
        $subdomain = $result->getSubdomain();
        if ($subdomain && array_intersect(preg_split('/\b/', $subdomain), $this->devSubdomainWords)) {
            return null;
        }

        return $domain;
    }

    /**
     * Returns licenses owned by a user.
     *
     * @param int $ownerId
     * @return CmsLicense[]
     */
    public function getLicensesByOwner(int $ownerId): array
    {
        $results = $this->_createLicenseQuery()
            ->where(['l.ownerId' => $ownerId])
            ->all();

        $licenses = [];
        foreach ($results as $result) {
            $licenses[] = new CmsLicense($result);
        }

        return $licenses;
    }

    /**
     * Returns licenses purchased by an order.
     *
     * @param int $orderId
     * @return CmsLicense[]]
     */
    public function getLicensesByOrder(int $orderId): array
    {
        $results = $this->_createLicenseQuery()
            ->innerJoin('craftnet_cmslicenses_lineitems l_li', '[[l_li.licenseId]] = [[l.id]]')
            ->innerJoin('commerce_lineitems li', '[[li.id]] = [[l_li.lineItemId]]')
            ->where(['li.orderId' => $orderId])
            ->all();

        $licenses = [];
        foreach ($results as $result) {
            $licenses[] = new CmsLicense($result);
        }

        return $licenses;
    }

    /**
     * Returns a license by its ID.
     *
     * @param int $id
     *
     * @return CmsLicense
     * @throws LicenseNotFoundException if $id is missing
     */
    public function getLicenseById(int $id): CmsLicense
    {
        $result = $this->_createLicenseQuery()
            ->where(['l.id' => $id])
            ->one();

        if (!$result) {
            throw new LicenseNotFoundException($id);
        }

        return new CmsLicense($result);
    }

    /**
     * Returns a license by its key.
     *
     * @param string $key
     *
     * @return CmsLicense
     * @throws LicenseNotFoundException if $key is missing
     */
    public function getLicenseByKey(string $key): CmsLicense
    {
        try {
            $key = $this->normalizeKey($key);
        } catch (InvalidArgumentException $e) {
            throw new LicenseNotFoundException($key, $e->getMessage(), 0, $e);
        }

        $result = $this->_createLicenseQuery()
            ->where(['l.key' => $key])
            ->one();

        if ($result === null) {
            // try the inactive licenses table
            $data = (new Query())
                ->select(['data'])
                ->from(['craftnet_inactivecmslicenses'])
                ->where(['key' => $key])
                ->scalar();

            if ($data === false) {
                throw new LicenseNotFoundException($key);
            }

            $data = Json::decode($data);
            $data['editionHandle'] = 'solo';
            unset($data['edition']);
            $license = new CmsLicense($data);

            if (!$license->ownerId) {
                $license->ownerId = User::find()
                    ->select('elements.id')
                    ->email($license->email)
                    ->scalar() ?: null;
            }

            $this->saveLicense($license, false);

            Craft::$app->getDb()->createCommand()
                ->delete('craftnet_inactivecmslicenses', [
                    'key' => $key
                ])
                ->execute();

            $note = "created by {$license->email}";
            if ($license->domain) {
                $note .= " for domain {$license->domain}";
            }
            $this->addHistory($license->id, $note, $data['dateCreated']);

            return $license;
        }

        return new CmsLicense($result);
    }

    /**
     * Saves a license.
     *
     * @param CmsLicense $license
     * @param bool $runValidation
     *
     * @return bool if the license validated and was saved
     * @throws Exception if the license validated but didn't save
     */
    public function saveLicense(CmsLicense $license, bool $runValidation = true): bool
    {
        if ($runValidation && !$license->validate()) {
            Craft::info('License not saved due to validation error.', __METHOD__);

            return false;
        }

        if (!$license->editionId) {
            $license->editionId = (int)CmsEdition::find()
                ->select('elements.id')
                ->handle($license->editionHandle)
                ->scalar();

            if ($license->editionId === false) {
                throw new Exception("Invalid Craft edition: {$license->editionHandle}");
            }
        }

        $data = [
            'editionId' => $license->editionId,
            'ownerId' => $license->ownerId,
            'expirable' => $license->expirable,
            'expired' => $license->expired,
            'autoRenew' => $license->autoRenew,
            'editionHandle' => $license->editionHandle,
            'email' => $license->email,
            'domain' => $license->domain,
            'key' => $license->key,
            'notes' => $license->notes,
            'privateNotes' => $license->privateNotes,
            'lastEdition' => $license->lastEdition,
            'lastVersion' => $license->lastVersion,
            'lastAllowedVersion' => $license->lastAllowedVersion,
            'lastActivityOn' => Db::prepareDateForDb($license->lastActivityOn),
            'lastRenewedOn' => Db::prepareDateForDb($license->lastRenewedOn),
            'expiresOn' => Db::prepareDateForDb($license->expiresOn),
            'dateCreated' => Db::prepareDateForDb($license->dateCreated),
        ];

        if (!$license->id) {
            $success = (bool)Craft::$app->getDb()->createCommand()
                ->insert('craftnet_cmslicenses', $data)
                ->execute();

            // set the ID an UID on the model
            $license->id = (int)Craft::$app->getDb()->getLastInsertID('craftnet_cmslicenses');
        } else {
            $success = (bool)Craft::$app->getDb()->createCommand()
                ->update('craftnet_cmslicenses', $data, ['id' => $license->id])
                ->execute();
        }

        if (!$success) {
            throw new Exception('License validated but didn’t save.');
        }

        return true;
    }

    /**
     * Adds a new record to a Craft license’s history.
     *
     * @param int $licenseId
     * @param string $note
     * @param string|null $timestamp
     */
    public function addHistory(int $licenseId, string $note, string $timestamp = null)
    {
        Craft::$app->getDb()->createCommand()
            ->insert('craftnet_cmslicensehistory', [
                'licenseId' => $licenseId,
                'note' => $note,
                'timestamp' => $timestamp ?? Db::prepareDateForDb(new \DateTime()),
            ], false)
            ->execute();
    }

    /**
     * Returns a license's history in chronological order.
     *
     * @param int $licenseId
     *
     * @return array
     */
    public function getHistory(int $licenseId): array
    {
        return (new Query())
            ->select(['note', 'timestamp'])
            ->from('craftnet_cmslicensehistory')
            ->where(['licenseId' => $licenseId])
            ->orderBy(['timestamp' => SORT_ASC])
            ->all();
    }

    /**
     * Claims a license for a user.
     *
     * @param User $user
     * @param string $key
     *
     * @throws LicenseNotFoundException
     * @throws Exception
     */
    public function claimLicense(User $user, string $key)
    {
        $license = $this->getLicenseByKey($key);

        // make sure the license doesn't already have an owner
        if ($license->ownerId) {
            throw new Exception('License has already been claimed.');
        }

        $license->ownerId = $user->id;
        $license->email = $user->email;

        if (!$this->saveLicense($license)) {
            throw new Exception('Could not save Craft license: '.implode(', ', $license->getErrorSummary(true)));
        }

        $this->addHistory($license->id, "claimed by {$user->email}");
    }

    /**
     * Finds unclaimed licenses that are associated the given user's email,
     * and and assigns them to the user.
     *
     * @param User $user
     * @param string|null $email the email to look for (defaults to the user's email)
     * @return int the total number of affected licenses
     */
    public function claimLicenses(User $user, string $email = null): int
    {
        return Craft::$app->getDb()->createCommand()
            ->update('craftnet_cmslicenses', [
                'ownerId' => $user->id,
            ], [
                'and',
                ['ownerId' => null],
                new Expression('lower([[email]]) = :email', [':email' => strtolower($email ?? $user->email)]),
            ], [], false)
            ->execute();
    }

    /**
     * Returns licenses by owner as an array.
     *
     * @param User $owner
     *
     * @return array
     */
    public function getLicensesArrayByOwner(User $owner)
    {
        $results = $this->getLicensesByOwner($owner->id);

        return $this->transformLicensesForOwner($results, $owner);
    }

    /**
     * Transforms licenses for the given owner.
     *
     * @param array $results
     * @param User $owner
     *
     * @return array
     */
    public function transformLicensesForOwner(array $results, User $owner)
    {
        $licenses = [];

        foreach ($results as $result) {
            $licenses[] = $this->transformLicenseForOwner($result, $owner);
        }

        return $licenses;
    }

    /**
     * Transforms a license for the given owner.
     *
     * @param CmsLicense $result
     * @param User $owner
     *
     * @return array
     */
    public function transformLicenseForOwner(CmsLicense $result, User $owner)
    {
        if ($result->ownerId === $owner->id) {
            $license = $result->getAttributes(['id', 'key', 'domain', 'notes', 'email', 'dateCreated']);
            $license['edition'] = $result->editionHandle;
        } else {
            $license = [
                'shortKey' => $result->getShortKey()
            ];
        }

        $pluginLicensesResults = Module::getInstance()->getPluginLicenseManager()->getLicensesByCmsLicenseId($result->id);


        // History

        $license['history'] = $this->getHistory($result->id);


        // Plugin licenses

        $pluginLicenses = [];

        foreach ($pluginLicensesResults as $key => $pluginLicensesResult) {
            if ($pluginLicensesResult->ownerId === $owner->id) {
                $pluginLicense = $pluginLicensesResult->getAttributes(['id', 'key']);
            } else {
                $pluginLicense = [
                    'shortKey' => $pluginLicensesResult->getShortKey(),
                ];
            }

            $plugin = null;

            if ($pluginLicensesResult->pluginId) {
                $pluginResult = Plugin::find()->id($pluginLicensesResult->pluginId)->status(null)->one();
                $plugin = $pluginResult->getAttributes(['name']);
            }

            $pluginLicense['plugin'] = $plugin;

            $pluginLicenses[] = $pluginLicense;
        }

        $license['pluginLicenses'] = $pluginLicenses;

        return $license;
    }

    /**
     * Deletes a license by its key.
     *
     * @param string $key
     * @throws LicenseNotFoundException if $key is missing
     */
    public function deleteLicenseByKey(string $key)
    {
        try {
            $key = $this->normalizeKey($key);
        } catch (InvalidArgumentException $e) {
            throw new LicenseNotFoundException($key, $e->getMessage(), 0, $e);
        }

        $rows = Craft::$app->getDb()->createCommand()
            ->delete('craftnet_cmslicenses', ['key' => $key])
            ->execute();

        if ($rows === 0) {
            throw new LicenseNotFoundException($key);
        }
    }

    /**
     * @return Query
     */
    private function _createLicenseQuery(): Query
    {
        return (new Query())
            ->select([
                'l.id',
                'l.editionId',
                'l.ownerId',
                'l.expirable',
                'l.expired',
                'l.autoRenew',
                'l.editionHandle',
                'l.email',
                'l.domain',
                'l.key',
                'l.notes',
                'l.privateNotes',
                'l.lastEdition',
                'l.lastVersion',
                'l.lastAllowedVersion',
                'l.lastActivityOn',
                'l.lastRenewedOn',
                'l.expiresOn',
                'l.dateCreated',
                'l.dateUpdated',
                'l.uid',
            ])
            ->from(['craftnet_cmslicenses l']);
    }
}
