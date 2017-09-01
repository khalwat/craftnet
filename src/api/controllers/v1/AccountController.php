<?php

namespace craftcom\api\controllers\v1;

use craftcom\oauthserver\Module as OauthServer;
use craftcom\api\controllers\BaseApiController;
use craft\elements\User;
use yii\web\Response;

/**
 * Class CraftIdController
 *
 * @package craftcom\api\controllers\v1
 */
class AccountController extends BaseApiController
{
    // Public Methods
    // =========================================================================

    /**
     * Handles /v1/craft-id requests.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        /*try {*/
            // Retrieve access token
            $accessToken = OauthServer::getInstance()->getAccessTokens()->getAccessTokenFromRequest();

            if ($accessToken) {
                // Check that this access token is associated with a user
                if ($accessToken->userId) {
                    // Check that the user has sufficient permissions to access the resource
                    $scopes = $accessToken->scopes;
                    $requiredScopes = ['purchasePlugins', 'existingPlugins', 'transferPluginLicense', 'deassociatePluginLicense'];

                    $hasSufficientPermissions = true;

                    foreach ($requiredScopes as $requiredScope) {
                        if (!in_array($requiredScope, $scopes)) {
                            $hasSufficientPermissions = false;
                        }
                    }

                    if ($hasSufficientPermissions) {
                        // User has sufficient permissions to access the resource


                        $user = User::find()->id($accessToken->userId)->one();

                        if($user) {

                            $purchasedPlugins = [];

                            foreach ($user->purchasedPlugins as $purchasedPlugin) {
                                $purchasedPlugins[] = [
                                    'name' => $purchasedPlugin->title,
                                    'developerName' => $purchasedPlugin->getAuthor()->developerName,
                                    'developerUrl' => $purchasedPlugin->getAuthor()->developerUrl,
                                ];
                            }

                            return $this->asJson([
                                'id' => $user->getId(),
                                'name' => $user->getFullName(),
                                'email' => $user->email,
                                'username' => $user->username,
                                'purchasedPlugins' => $purchasedPlugins,
                                'cardNumber' => $user->cardNumber,
                                'cardExpiry' => $user->cardExpiry,
                                'cardCvc' => $user->cardCvc,
                                'businessName' => $user->businessName,
                                'businessVatId' => $user->businessVatId,
                                'businessAddressLine1' => $user->businessAddressLine1,
                                'businessAddressLine2' => $user->businessAddressLine2,
                                'businessCity' => $user->businessCity,
                                'businessState' => $user->businessState,
                                'businessZipCode' => $user->businessZipCode,
                                'businessCountry' => $user->businessCountry,
                            ]);
                        }

                        throw new \Exception("Couldn’t retrieve user.");
                    }

                    throw new \Exception("Insufficient permissions.");
                }

                throw new \Exception("Couldn’t get user identifier.");
            }

            throw new \Exception("Couldn’t get access token.");
/*
        } catch (\Exception $e) {
            return $this->asErrorJson($e->getMessage());
        }*/
    }
}