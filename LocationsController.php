<?php

namespace app\api\controllers;

use app\api\resources\LocationsDetailsResource;
use app\api\resources\LocationsResource;
use app\api\resources\StockResource;
use app\api\resources\TransfersResource;
use app\models\Transfers;
use Yii;

use yii\rest\ActiveController;
use yii\filters\auth\HttpBearerAuth;
use yii\helpers\Json;
use Exception;



/**
 * AdminController implements the CRUD actions for Admin model.
 */
class LocationsController extends ActiveController
{

    public $modelClass = 'app\api\resources\LocationsResource';
    private $_errors   = [];
   
    public function init()
    {
       
        parent::init();
        // Yii::$app->user->enableSession = false;
      
    }


    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => HttpBearerAuth::className(),
        ];
        return $behaviors;
    }

    public function actionPatch($id)
    {
        $params = Yii::$app->request->bodyParams;

        if(!isset($params['contact_id']) && isset($params['company_id'])) {

            return LocationsDetailsResource::updateAll(['company_id' => $params['company_id'] ], 
                                            ['location_id' => $id, 'user_id' => $params['user_id']]);

        } else if (!isset($params['company_id']) && isset($params['contact_id'])) {

            return LocationsDetailsResource::updateAll([ 'contact_id' => $params['contact_id'] ], 
                                            ['location_id' => $id, 'user_id' => $params['user_id']]);

        } else {

           return LocationsDetailsResource::updateAll([ 'contact_id' => $params['contact_id'], 'company_id' => $params['company_id'] ], 
                                            ['location_id' => $id, 'user_id' => $params['user_id']]);

        }
    }

    public function actionPatchMachines($id) {
        $params = Yii::$app->request->bodyParams;
        $result = array_combine(json_decode($params['linkedId']), json_decode($params['machine_id']));
        foreach($result as $key=>$value) {
            LocationsDetailsResource::updateAll(['machine_id' => $value], ['id' => $key]);
        }
    }
    
    public function actionCreateLocation() {

        $bodyParams = Yii::$app->getRequest()->getBodyParams();

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $location = new LocationsResource();
            if(!$location->createLocation($bodyParams)){
                $this->_errors['createLocationError'] = $location->errors;
                throw new Exception('Errors when creating  a new Location');
            }
           
            $bodyParams['location_id'] = $location->id;
            $transfer = new TransfersResource();
            if(($result = $transfer->createTransfer($bodyParams)) !== true) {
                $this->_errors['createTransfer'] = $transfer->errors;
                throw new Exception('Errors when creating  a new Transfer');
            } 
   
            $locationsDetails = new LocationsDetailsResource();
            if(($result =  $locationsDetails->createLocationDetail($bodyParams)) !== true) {
                $this->_errors['locationDetailsError'] = $result;
                throw new Exception('Insert into linked Transfer Tables had some Errors');
            }

            $transaction->commit();
            return LocationsDetailsResource::getLocations($bodyParams['warehouse']);
        } catch (\Throwable $e){
            $transaction->rollBack();
            var_dump($this->_errors);
            return $this->_errors;
        }

    }

    /**
     * Checks the privilege of the current user.
     *
     * This method should be overridden to check whether the current user has the privilege
     * to run the specified action against the specified data model.
     * If the user does not have access, a [[ForbiddenHttpException]] should be thrown.
     *
     * @param string $action the ID of the action to be executed
     * @param \yii\base\Model $model the model to be accessed. If `null`, it means no specific model is being accessed.
     * @param array $params additional parameters
     * @throws ForbiddenHttpException if the user does not have access
     */
    public function checkAccess($action, $model = null, $params = [])
    {
      
        // check if the user can access $action and $model
        // throw ForbiddenHttpException if access should be denied

        if ($action === 'delete') {
            if (\Yii::$app->user->can('delete') === false)
                throw new \yii\web\ForbiddenHttpException(sprintf('You are not allowed to %s locations.', $action));
        }
    }

    public function actionLocationDetails($id) 
    {
        $item = $this->modelClass::findOne(['id'=>$id]);
        return $item->toArray([],['company', 'machine', 'contact', 'income']);
    }

}


