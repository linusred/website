<?php
namespace Destiny\Commerce;

use Destiny\Common\Application;
use Destiny\Common\Config;
use Destiny\Common\Exception;
use Destiny\Common\Service;
use Destiny\Common\Utils\Date;
use Destiny\PayPal\PayPalApiService;
use Doctrine\DBAL\DBALException;
use PDO;

/**
 * @method static SubscriptionsService instance()
 */
class SubscriptionsService extends Service {

    /**
     * @throws DBALException
     */
    public function addSubscription(array $subscription = null): int {
        $conn = Application::getDbConn();
        $conn->insert('dfl_users_subscriptions', $subscription);
        return intval($conn->lastInsertId());
    }

    /**
     * Update subscription
     * @throws DBALException
     */
    public function updateSubscription(array $subscription = null) {
        $conn = Application::getDbConn();
        $conn->update('dfl_users_subscriptions', $subscription, ['subscriptionId' => $subscription ['subscriptionId']]);
    }

    /**
     * @throws Exception
     * @throws DBALException
     */
    public function cancelSubscription(array $subscription, bool $removeRemaining, int $userId): array {
        $payPalAPIService = PayPalApiService::instance();
        $conn = Application::getDbConn();
        try {
            $conn->beginTransaction();

            // Set recurring flag
            if ($subscription['recurring'] == 1) {
                $subscription['recurring'] = 0;
            }
            // Set subscription to cancelled
            if ($removeRemaining) {
                $subscription['status'] = SubscriptionStatus::CANCELLED;
            }
            // Cancel the payment profile
            if (!empty($subscription['paymentProfileId']) && strcasecmp($subscription['paymentStatus'], PaymentStatus::ACTIVE) === 0) {
                $payPalAPIService->cancelPaymentProfile($subscription['paymentProfileId']);
                $subscription['paymentStatus'] = PaymentStatus::CANCELLED;
            }

            $data = [
                'subscriptionId' => $subscription['subscriptionId'],
                'paymentStatus' => $subscription['paymentStatus'],
                'recurring' => $subscription['recurring'],
                'status' => $subscription['status'],
                'cancelDate' => Date::getSqlDateTime(),
                'cancelledBy' => $userId,
            ];

            $this->updateSubscription($data);
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw new Exception("Error updating subscription", $e);
        }
        return $subscription;
    }

    /**
     * @return array||null
     */
    public function getSubscriptionType(string $typeId): array {
        return Config::$a['commerce']['subscriptions'][$typeId] ?? null;
    }

    /**
     * @return array|false
     * @throws DBALException
     */
    public function findById(int $subscriptionId) {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
            SELECT * FROM dfl_users_subscriptions
            WHERE subscriptionId = :subscriptionId
            LIMIT 1
        ');
        $stmt->bindValue('subscriptionId', $subscriptionId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Return recurring subscriptions that have a expired end date, but a active profile.
     * @throws DBALException
     */
    public function getRecurringSubscriptionsToRenew(): array {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
            SELECT s.* FROM dfl_users_subscriptions s
            WHERE s.recurring = 1 AND s.paymentStatus = :paymentStatus 
            AND s.endDate <= NOW() AND s.billingNextDate > NOW()
        ');
        $stmt->bindValue('paymentStatus', PaymentStatus::ACTIVE, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Return all subscriptions where the state is active and the end date is < now
     * @throws DBALException
     */
    public function getSubscriptionsToExpire(): array {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('SELECT subscriptionId, userId FROM dfl_users_subscriptions WHERE status = :status AND endDate <= NOW()');
        $stmt->bindValue('status', SubscriptionStatus::ACTIVE, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get the first active subscription
     * Note: This does not take into account end date.
     * It relies on the subscription status Active.
     * It also orders by subscriptionTier and createdDate
     * Returning only the highest and newest tier subscription.
     *
     * @return array|false
     * @throws DBALException
     */
    public function getUserActiveSubscription(int $userId) {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
          SELECT s.*, gifter.username `gifterUsername` FROM dfl_users_subscriptions s
          LEFT JOIN dfl_users gifter ON (gifter.userId = s.gifter)
          WHERE s.userId = :userId AND s.status = :status 
          ORDER BY s.subscriptionTier DESC, s.createdDate DESC
          LIMIT 1
        ');
        $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue('status', SubscriptionStatus::ACTIVE, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * @throws DBALException
     */
    public function getUserActiveAndPendingSubscriptions(int $userId): array {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
          SELECT s.*, gifter.username `gifterUsername` FROM dfl_users_subscriptions s
          LEFT JOIN dfl_users gifter ON (gifter.userId = s.gifter)
          WHERE s.userId = :userId AND (s.status = :activeStatus OR s.status = :pendingStatus)
          ORDER BY s.subscriptionTier DESC, s.createdDate DESC
        ');
        $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue('activeStatus', SubscriptionStatus::ACTIVE, PDO::PARAM_STR);
        $stmt->bindValue('pendingStatus', SubscriptionStatus::PENDING, PDO::PARAM_STR);
        $stmt->execute();
        return array_map(function($item) {
            $item['type'] = $this->getSubscriptionType($item['subscriptionType']);
            return $item;
        }, $stmt->fetchAll());
    }

    /**
     * @throws DBALException
     */
    public function searchAll(array $params): array {
        $conn = Application::getDbConn();
        $clauses = [];
        if (!empty($params['search'])) {
            $clauses[] = 'u.username LIKE :search';
        }
        if (!empty($params['recurring'])) {
            $clauses[] = 's.recurring = :recurring';
        }
        if (!empty($params['status'])) {
            $clauses[] = 's.status = :status';
        }
        if (!empty($params['tier'])) {
            $clauses[] = 's.subscriptionTier = :tier';
        }
        $q = '
          SELECT
            SQL_CALC_FOUND_ROWS
            s.subscriptionId,
            u.userId,
            u.username,
            s.subscriptionType,
            s.createdDate,
            s.endDate,
            s.subscriptionSource,
            s.recurring,
            s.status,
            s.gifter,
            u2.username `gifterUsername`
          FROM dfl_users_subscriptions AS s
          INNER JOIN dfl_users AS u ON (u.userId = s.userId)
          LEFT JOIN dfl_users AS u2 ON (u2.userId = s.gifter)
        ';
        if (count($clauses) > 0) {
            $q .= ' WHERE ' . join(' AND ', $clauses);
        }
        $q.= ' ORDER BY s.createdDate DESC';
        $q.= ' LIMIT :start, :limit ';
        $stmt = $conn->prepare($q);

        if (!empty($params['search'])) {
            $stmt->bindValue('search', $params['search'], PDO::PARAM_STR);
        }
        if (!empty($params['recurring'])) {
            $stmt->bindValue('recurring', intval($params['recurring']), PDO::PARAM_INT);
        }
        if (!empty($params['status'])) {
            $stmt->bindValue('status', $params['status'], PDO::PARAM_STR);
        }
        if (!empty($params['tier'])) {
            $stmt->bindValue('tier', $params['tier'], PDO::PARAM_STR);
        }

        $stmt->bindValue('start', ($params['page'] - 1) * $params['size'], PDO::PARAM_INT);
        $stmt->bindValue('limit', (int) $params['size'], PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(function($item) {
            $item['type'] = $this->getSubscriptionType($item['subscriptionType']);
            return $item;
        }, $stmt->fetchAll());

        $pagination = [];
        $pagination ['list'] = $items;
        $pagination ['total'] = $conn->fetchColumn('SELECT FOUND_ROWS()');
        $pagination ['totalpages'] = ceil($pagination ['total'] / $params['size']);
        $pagination ['pages'] = 5;
        $pagination ['page'] = $params['page'];
        $pagination ['limit'] = $params['size'];

        return $pagination;
    }

    /**
     * @return array|false
     * @throws DBALException
     */
    public function findByUserIdAndStatus(int $userId, string $status) {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
          SELECT * FROM dfl_users_subscriptions 
          WHERE userId = :userId AND status = :status 
          ORDER BY createdDate DESC 
          LIMIT 1
        ');
        $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue('status', $status, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * @throws DBALException
     */
    public function findCompletedByGifterId(int $gifterId, int $limit = 100, int $start = 0): array {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
          SELECT s.*, u2.username, u.username `gifterUsername` 
          FROM dfl_users_subscriptions s
          LEFT JOIN dfl_users u ON (u.userId = s.gifter)
          LEFT JOIN dfl_users u2 ON (u2.userId = s.userId)
          WHERE s.gifter = :gifter AND (s.status = :active OR s.status = :cancelled OR s.status = :expired)
          ORDER BY endDate DESC
          LIMIT :start,:limit
        ');
        $stmt->bindValue('active', SubscriptionStatus::ACTIVE, PDO::PARAM_STR);
        $stmt->bindValue('cancelled', SubscriptionStatus::CANCELLED, PDO::PARAM_STR);
        $stmt->bindValue('expired', SubscriptionStatus::EXPIRED, PDO::PARAM_STR);
        $stmt->bindValue('gifter', $gifterId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('start', $start, PDO::PARAM_INT);
        $stmt->execute();
        $gifts = $stmt->fetchAll();
        for ($i = 0; $i < count($gifts); $i++) {
            // TODO possible to assign null to this.
            $gifts[$i]['type'] = $this->getSubscriptionType($gifts [$i]['subscriptionType']);
        }
        return $gifts;
    }

    /**
     * @throws DBALException
     */
    public function findByGifterIdAndStatus(int $gifterId, string $status): array {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
          SELECT s.*, u2.username, u.username `gifterUsername` 
          FROM dfl_users_subscriptions s
          LEFT JOIN dfl_users u ON (u.userId = s.gifter)
          LEFT JOIN dfl_users u2 ON (u2.userId = s.userId)
          WHERE s.gifter = :gifter AND s.status = :status
          ORDER BY endDate ASC
        ');
        $stmt->bindValue('gifter', $gifterId, PDO::PARAM_INT);
        $stmt->bindValue('status', $status, PDO::PARAM_STR);
        $stmt->execute();
        $gifts = $stmt->fetchAll();
        for ($i = 0; $i < count($gifts); $i++) {
            // TODO possible to assign null to this.
            $gifts[$i]['type'] = $this->getSubscriptionType($gifts[$i]['subscriptionType']);
        }
        return $gifts;
    }

    /**
     * @throws DBALException
     */
    public function findByUserId(int $userId, $limit = 100, $start = 0): array {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
          SELECT * FROM dfl_users_subscriptions
          WHERE userId = :userId
          ORDER BY createdDate DESC LIMIT :start,:limit
        ');
        $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('start', $start, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return boolean
     * @throws DBALException
     */
    public function canUserReceiveGift(int $gifter, int $giftee): bool {
        if ($gifter == $giftee) {
            return false;
        }

        // Make sure the the giftee accepts gifts
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('SELECT userId FROM dfl_users WHERE userId = :userId AND allowGifting = 1');
        $stmt->bindValue('userId', $giftee, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() <= 0) {
            return false;
        }

        // make sure the giftee doesn't have an active subscription
        $subscription = $this->getUserActiveSubscription($giftee);
        if (!empty($subscription)) {
            return false;
        }

        return true;
    }

    /**
     * @return array|false
     * @throws DBALException
     */
    public function findByPaymentProfileId(string $paymentProfileId) {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
            SELECT * FROM dfl_users_subscriptions
            WHERE paymentProfileId = :paymentProfileId
            LIMIT 1
        ');
        $stmt->bindValue('paymentProfileId', $paymentProfileId, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * @throws DBALException
     */
    public function findCompletedByUserId(int $userId, int $limit = 100, int $start = 0): array {
        $conn = Application::getDbConn();
        $stmt = $conn->prepare('
          SELECT * FROM dfl_users_subscriptions s
          WHERE s.`userId` = :userId AND (s.status = :active OR s.status = :cancelled OR s.status = :expired)
          ORDER BY createdDate DESC 
          LIMIT :start,:limit
        ');
        $stmt->bindValue('active', SubscriptionStatus::ACTIVE, PDO::PARAM_STR);
        $stmt->bindValue('expired', SubscriptionStatus::EXPIRED, PDO::PARAM_STR);
        $stmt->bindValue('cancelled', SubscriptionStatus::CANCELLED, PDO::PARAM_STR);
        $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('start', $start, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}