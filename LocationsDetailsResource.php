<?php

namespace app\api\resources;

use Yii;
use app\models\LocationsDetails;
use Exception;


/**
 * This is the model class for table "locations_details".
 *
 * @property int $id
 * @property int $user_id
 * @property int $location_id
 * @property int $contact_id
 * @property int $company_id
 * @property int $machine_id
 *
 * @property Company $company
 * @property Contact $contact
 * @property Location $location
 * @property Machine $machine
 * @property User $user
 */
class LocationsDetailsResource extends LocationsDetails
{
    private $_errors = [];

    public function fields() 
    {
        return ['location_id'];
    }

    public function extraFields() 
    {
        return ['stockPerMachine', 'machine', 'product', 'location'];
    }

    public static function find()
    {
        $childIds = AdminResource::findChildIds(Yii::$app->user->id);
        $query = new \app\models\query\LocationsQuery(get_called_class());
        return $query->andWhere(['user_id' => $childIds]);
    }

    public function getStockPerMachine()
    {
        return StockResource::find()->select('SUM(quantity) AS quantity')
                                    ->where([
                                            'location_id' => $this->location_id, 
                                            'product_id' => $this->product_id, 
                                            'linked_machine' => $this->machine_id])
                                    ->andWhere(['not', ['linked_machine' => null]])
                                    ->one();
    }

    public function getMachine() 
    {
        return $this->hasOne(MachinesResource::className(), ['id' => 'machine_id'])->select('id, make, model');
    }

    public function getProduct() 
    {
        return $this->hasOne(ProductsResource::className(), ['id' => 'product_id'])->select('id, name, sale_price');
    }

    public function getLocation() 
    {
        return $this->hasOne(LocationsResource::className(), ['id' => 'location_id']);
    }
  

    // static function getWarehouses()
    // {
    //     $results = self::find()->andWhere(['warehouse' => 'Y'])->all();
    //     // using the extra fields of the model we add data, to each record found, with location data
    //     foreach($results as $key => $value) {
    //         $results[$key] = $value->toArray([], ['location']);
    //         $results[$key] = $results[$key]['location'];
    //     }
    //     return $results;

    // }

    public static function getLocations($boolean)
    {
        $results = self::find()->andWhere(['warehouse' => $boolean])->all();
        // using the extra fields of the model we add data, to each record found, with location data
        foreach($results as $key => $value) {
            $results[$key] = $value->toArray([], ['location']);
            $results[$key] = $results[$key]['location'];
        }
        return $results;

    }

    public function createLocationDetail($params)
    {
        if(isset($params['machine'])) {
            foreach($params['machine'] as $key => $value){
                if($result = $this->prepareAttributeValues($params, $value)) {
                    return $this->save() ? true : $this->errors;
                } else {
                    return $result;
                }
            }
        } else {
            $this->prepareAttributeValues($params);
            $this->save();
                //END : insert entry in locations_details table
            return $this->errors ?: null;
        }
    }

    private function prepareAttributeValues($params, $machine = null)
    {
        if((int)$params['company']['id'] === 0) {
            $company   = new CompanyResource();
            if(is_int($companyId = $company->createCompany($params))) {
                $this->company_id = $companyId;
            } else {
               $this->_errors['error']['companyResource'] = $companyId;
            }
        } else {
            $this->company_id = (int)$params['company']['id'];
        }
     
        if((int)$params['contact']['id'] === 0) {
            $contact = new ContactsResource();
            if(is_int($contactId = $contact->createContact($params))) {
                $this->contact_id = $contactId;
            } else {
                $this->_errors['error']['contactResource'] = $contactId;
            }
        } else {
            $this->contact_id = (int)$params['contact']['id'];
        }
     
        if($machine !== null) {
            if((int)$machine['machine_id'] === 0) {
                $machineNew = new MachinesResource();
                if(is_int($machineId = $machineNew->createMachine($machine))) {
                    $this->machine_id = $machineId;
                } else {
                    $this->_errors['error']['machineResource'] = $machineId;
                }
            } else {
                $this->machine_id = $machine['machine_id'];
            }
            $this->product_id  = $machine['product_id'];
            $this->location_id = $params['location_id'];
            $this->warehouse   = $params['warehouse'];
       
        } else {
            $this->location_id = $params['location']['id'];
            $this->warehouse   = $params['warehouse'];
        }
      
        return isset($this->_errors['error']) ?: true;
    }

    public function getWarehouse()
    {
        return $this->find()->andWhere(['warehouse' => 'Y'])->one();
    }
}
