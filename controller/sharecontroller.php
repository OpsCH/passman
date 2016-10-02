<?php
/**
 * Nextcloud - passman
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Sander Brand <brantje@gmail.com>
 * @copyright Sander Brand 2016
 */

namespace OCA\Passman\Controller;

use OCA\Passman\Db\ShareRequest;
use OCA\Passman\Db\Vault;
use OCA\Passman\Service\CredentialService;
use OCA\Passman\Service\NotificationService;
use OCA\Passman\Service\ShareService;
use OCP\IRequest;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\ApiController;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUser;

use OCA\Passman\Service\VaultService;
use OCA\Passman\Service\ActivityService;
use OCA\Passman\Activity;


class ShareController extends ApiController {
	private $userId;
	private $activityService;
	private $groupManager;
	private $userManager;
	private $vaultService;
	private $shareService;
	private $credentialService;
	private $notificationService;

	private $limit = 50;
	private $offset = 0;

	public function __construct($AppName,
								IRequest $request,
								$UserId,
								IGroupManager $groupManager,
								IUserManager $userManager,
								ActivityService $activityService,
								VaultService $vaultService,
								ShareService $shareService,
								CredentialService $credentialService,
								NotificationService $notificationService
	) {
		parent::__construct($AppName, $request);

		$this->userId = $UserId;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->activityService = $activityService;
		$this->vaultService = $vaultService;
		$this->shareService = $shareService;
		$this->credentialService = $credentialService;
		$this->notificationService = $notificationService;
	}


	/**
	 * @NoAdminRequired
	 */
	public function applyIntermediateShare($item_id, $item_guid, $vaults, $permissions) {
		/**
		 * Assemble notification
		 */
		$credential = $this->credentialService->getCredentialById($item_id, $this->userId->getUID());
		$credential_owner = $credential->getUserId();
		$result = $this->shareService->createBulkRequests($item_id, $item_guid, $vaults, $permissions, $credential_owner);
		if ($credential) {
			$processed_users = array();

			foreach ($result as $vault){
				if(!in_array($vault->getTargetUserId(), $processed_users)){
					$target_user = $vault->getTargetUserId();
					$notification = array(
						'from_user' => ucfirst($this->userId->getDisplayName()),
						'credential_label' => $credential->getLabel(),
						'credential_id' => $credential->getId(),
						'item_id' => $credential->getId(),
						'target_user' => $target_user,
						'req_id' => $vault->getId()
					);
					$this->notificationService->credentialSharedNotification(
						$notification
					);
					array_push($processed_users, $target_user);
				}
			}


		}
		return new JSONResponse($result);
	}

	/**
	 * @NoAdminRequired
	 */
	public function searchUsers($search) {
		$users = array();
		$usersTmp = $this->userManager->searchDisplayName($search, $this->limit, $this->offset);

		foreach ($usersTmp as $user) {
			if ($this->userId != $user->getUID() && count($this->vaultService->getByUser($user->getUID())) >= 1) {
				$users[] = array(
					'text' => $user->getDisplayName(),
					'uid' => $user->getUID(),
					'type' => 'user'
				);
			}
		}
		return $users;
	}


	/**
	 * @NoAdminRequired
	 */
	public function search($search) {
		$user_search = $this->searchUsers($search);
		return new JSONResponse($user_search);
	}


	/**
	 * @NoAdminRequired
	 */
	public function getVaultsByUser($user_id) {
		$user_vaults = $this->vaultService->getByUser($user_id);
		$result = array();
		foreach ($user_vaults as $vault) {
			array_push($result,
				array(
					'vault_id' => $vault->getId(),
					'guid' => $vault->getGuid(),
					'public_sharing_key' => $vault->getPublicSharingKey(),
					'user_id' => $user_id,
				));
		}
		return new JSONResponse($result);
	}

    /**
     * @NoAdminRequired
     * @param $credential
     */
	public function share($credential) {

		$link = '';
		$this->activityService->add(
			'item_shared', array($credential->label, $this->userId),
			'', array(),
			$link, $this->userId, Activity::TYPE_ITEM_ACTION);
	}

	/**
	 * @NoAdminRequired
	 */
	public function savePendingRequest($item_guid, $target_vault_guid, $final_shared_key) {
		$this->shareService->applyShare($item_guid, $target_vault_guid, $final_shared_key);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getPendingRequests() {
		$requests = $this->shareService->getUserPendingRequests($this->userId->getUID());
		$results = array();
		foreach ($requests as $request){
			$result = $request->jsonSerialize();
			$c = $this->credentialService->getCredentialLabelById($request->getItemId());
			$result['credential_label'] = $c->getLabel();
			array_push($results, $result);
		}
		return new JSONResponse($results);
	}

    /**
     * Obtains the list of credentials shared with this vault
     * @NoAdminRequired
     */
	public function getSharedItems($vault_guid){

    }

	public function deleteShareRequest($share_request_id){
		echo $share_request_id;

		$manager = \OC::$server->getNotificationManager();
		$notification = $manager->createNotification();
		$notification->setApp('passman')
			->setObject('passman_share_request', $share_request_id)
			->setUser($this->userId->getUID());
		$manager->markProcessed($notification);
		//@TODO load other requests and delete them by item id.
		$this->shareService->deleteShareRequestById($share_request_id);
	}

}