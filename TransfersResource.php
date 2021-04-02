<?php

namespace app\api\resources;

use Yii;
use app\models\Transfers;
use app\api\resources\CompanyResource;
use Exception;

class TransfersResource extends Transfers
{
    private $_errors = [];

    public function fields()
    {
        return [ 'id', 'number', 'date', 'quantity', 'source', 'destination', 'items'];
    }

    public static function find()
    {
        $childIds = AdminResource::findChildIds(Yii::$app->user->id);
        $query = new \app\models\query\TransfersQuery(get_called_class());
        return $query->andWhere(['user_id' => $childIds]);
    }

    public function getSource()
    {
        return $this->hasOne(LocationsResource::className(), ['id' => 'source_location_id'])->select('name, address')->where(['user_id' => Yii::$app->user->id]);
    }
    public function getDestination()
    {
        return $this->hasOne(LocationsResource::className(), ['id' => 'destination_location_id'])->select('name, address')->where(['user_id' => Yii::$app->user->id]);
    }
    public function getItems()
    {
        return $this->hasMany(TransferItemsResource::className(), ['transfer_id' => 'id'])->where(['user_id' => Yii::$app->user->id]);
    }

    private function _prepareTransferProperties($bodyParams)
    {
        $this->number                  = $this->getNextTransferNumber();
        $this->date                    = $bodyParams['date_installed'];
        $this->source_location_id      = $bodyParams['warehouseId'];
        $this->destination_location_id = $bodyParams['location_id'];
        $this->quantity                = array_sum(array_column($bodyParams, 'quantity'));
    }

    private function _checkIfThereAreProductsAndSetTheirIds($machinesArray)
    {
        foreach($machinesArray as $key=>$machine) {
            if((int)$machine['machine_id'] === 0) {
                $machineNew = new MachinesResource();
                if($machineId = $machineNew->createMachine($machine)){
                    $machinesArray[$key]['machine_id'] = $machineId;
                }
            }

            if($machine['productName'] !== '' && is_null($machine['product_id'])) {
                $newProduct = new ProductsResource();
                $machinesArray[$key]['product_id'] = $newProduct->saveWithoutReceptionAndReturnId($machine['productName']);
                $this->quantity += $machine['quantity'];
            } else if($machine['productName'] === '' && is_null($machine['product_id'])){
                $machinesArray['noProducts'] = true;
            }


        }
   
        return $machinesArray;
    }

    public function createTransfer(&$params) 
    {
    
        $this->_prepareTransferProperties($params);
        $params['machine'] = $this->_checkIfThereAreProductsAndSetTheirIds($params['machine']);
    

        if(isset($params['machine']) && isset($params['machine']['noProducts'])) {
            return true;
        }

        if(isset($params['products']) && is_null($params['products'])) {
            return true;
        }

        $transaction = Yii::$app->db->beginTransaction();

        try {
         
            if(!$this->save()) {
                $this->_errors['error']['transfer'] = $this->errors;
                throw new Exception('Transfer Error');
            }

            $params['transfer_id'] = $this->id;
            if(($result = $this->insertIntoLinkedTables($params)) !== true) {
                $this->errors['error']['linkedTransferTables'] = $result;
                throw new Exception('Insert into linked Transfer Tables had some Errors');
            }
    
            $transaction->commit();
            // return TransfersResource::find()->all();
            return  true;

        } catch (\Throwable $e) {
            $transaction->rollBack();
            return $this->_errors;
        }
          
    }

    private function getNextTransferNumber()
    {
        $lastNumber = $this->find()->orderBy(['number' => SORT_DESC])->one();
        $number = $lastNumber ? $lastNumber->number : 0;
        return ++$number;
    }

    public function insertIntoLinkedTables($params)
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $arrayOfProductsOrMachines = isset($params['products']) ?: $params['machine']; 

            foreach($arrayOfProductsOrMachines as $item){
                $params['product_id'] = $item['product_id'];
                $params['quantity']   = $item['quantity'];
                $params['linked_machine'] = isset($item['machine_id']) ? $item['machine_id'] : '';

                $transferItem = new TransferItemsResource();
                if(($result = $transferItem->createTransferItem($params)) !== true) {
                    $this->_errors['error']['transferItem'] = $result;
                    throw new Exception('Transfer Item had some Errors');
                }
           
                $sourceStock = new StockResource();
                if(($result = $sourceStock->createSourceStock($params)) !== true) {
                    $this->errors['error']['sourceStock'] = $result;
                    throw new Exception('Source Stock had some Errors');
                }
                $destinationStock = new StockResource();
                if(($result = $destinationStock->createDestinationStock($params)) !== true) {
                    $this->errors['error']['destinationStock'] = $result;
                    throw new Exception('Destination Stock had some Errors');
                }
          
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            return $this->_errors;
        }
    }

    static function emptyMachineAndMakeTransfer()
    {
        $errors = [];
        $params = Yii::$app->request->bodyParams;
        $params['number'] = self::getNextTransferNumber();
        $params['products'][0]['product_id'] = $params['product_id'];
        $params['products'][0]['quantity'] = $params['quantity']; 
       
        self::createTransfer($params);

        $stock = new StockResource();
        $stock->user_id        = Yii::$app->user->id;
        $stock->location_id    = $params['source_location_id'];
        $stock->linked_machine = $params['machine_id'];
        $stock->product_id     = $params['product_id'];
        $stock->quantity       = (int) $params['soldQuantity'] * (-1);
        $stock->income_id      = $params['income_id'];
        if($stock->save()) {

        } else {
           return $stock->errors;
        }

           //We get the details for the location including the machine id and actual product id in use
        $old_locationDetails = LocationsDetailsResource::findOne(['user_id' => Yii::$app->user->id, 
                                                            'location_id' => $params['source']['id'],
                                                            'machine_id'  => $params['machine_id']
                                                            ]);
        $old_locationDetails->product_id = $params['new_product_id'];
        $old_locationDetails->update();                                         
        $new_locationDetails = LocationsDetailsResource::find()->where(['user_id' => Yii::$app->user->id, 
                                                                        'location_id' => $params['source']['id']
                                                                        ])
                                                                ->all();                                          

        //We then calculate from Stock table the quantity, adding and substracting the values registered in the past
        //Using the relation between location_details table and Stock table described in the LocationsDetailsResource Model
        //The relations are marked in the extraFields of the Model, so we add the data for each record found in locations_details table
        foreach($new_locationDetails as $key => $item) {
            $new_locationDetails[$key] = $item->toArray([],['stockPerMachine', 'machine', 'product']);
        }

        return $new_locationDetails;
    }

}
