<?php

namespace Modules\Isite\Http\Controllers\Api;

use Illuminate\Session\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Ihelpers\Http\Controllers\Api\BaseApiController;
use Modules\Isite\Transformers\SettingTransformer;
use Modules\Setting\Repositories\SettingRepository;
use Nwidart\Modules\Module;
use Illuminate\Support\Str;

class SettingApiController extends BaseApiController
{
  /**
   * @var PaypalconfigRepository
   */

  private $settings;
  /**
   * @var Module
   */
  private $module;
  /**
   * @var Store
   */
  private $session;
  public function __construct(SettingRepository $settings, Store $session)
  {
    
    $this->settings = $settings;
    $this->module = app('modules');
    $this->session = $session;
  }
  
 
  /**
   * GET ITEMS
   *
   * @return mixed
   */
  public function index(Request $request)
  {
    try {
      //Get Parameters from URL.
      $params = $this->getParamsRequest($request);
      
      $modulesWithSettings = $this->settings->moduleSettings($this->module->enabled());
      
      $dbSettings = [];
      $translatableSettings = [];
      $plainSettings = [];
      
      // fetching translatable, plain, and DB setting by each module enabled with settings
      foreach ($modulesWithSettings as $key => $module){
        $translatableSettings[$key] = $this->settings->translatableModuleSettings($key);
        $plainSettings[$key] = $this->settings->plainModuleSettings($key);
        $dbSettings[$key] = $this->settings->savedModuleSettings($key);
      }

      /*=== SETTINGS ===*/
      $assignedSettings = [];
      if (isset($params->settings)) {
        if (isset($params->settings['assignedSettings']) && !empty($params->settings['assignedSettings'])) {
          $assignedSettings = $params->settings['assignedSettings'];
        }
      }
     
      // merging translatable and plain settings
      $mergedSettings = array_merge_recursive($translatableSettings,$plainSettings);
     
      $response = ["data" => $this->transformSettings($mergedSettings, $dbSettings, $assignedSettings)];
      
    } catch (\Exception $e) {
      $status = $this->getStatusError($e->getCode());
      $response = ["errors" => $e->getMessage()];
    }
    
    //Return response
    return response()->json($response, $status ?? 200);
  }
  
  
  /**
     * GET A ITEM
     *
     * @param $criteria
     * @return mixed
     */
    public function show($criteria,Request $request)
    {
      try {
        //Get Parameters from URL.
        $params = $this->getParamsRequest($request);
  
        $module = $this->module->find($criteria);
        
        //Break if no found item
        if(!$module) throw new \Exception('Item not found',404);
  
        $this->session->put('module', $module->getLowerName());

        $dbSettings = $this->settings->findByModule($module->getLowerName());

        //Response
        $response = ["data" =>  SettingTransformer::collection($dbSettings)];
  
      } catch (\Exception $e) {
        $status = $this->getStatusError($e->getCode());
        $response = ["errors" => $e->getMessage()];
      }
  
      //Return response
      return response()->json($response, $status ?? 200);
    }
  
  /**
   * UPDATE ITEM
   *
   * @param $criteria
   * @param Request $request
   * @return mixed
   */
  public function createOrUpdate(Request $request)
  {
    \DB::beginTransaction(); //DB Transaction
    try {
  
      $data = $request->input('attributes');
      
      $this->settings->createOrUpdate($data);
      
      //Response
      $response = ["data" => 'Item Updated'];
      \DB::commit();//Commit to DataBase
    } catch (\Exception $e) {
      \DB::rollback();//Rollback to Data Base
      $status = $this->getStatusError($e->getCode());
      $response = ["errors" => $e->getMessage()];
    }
    
    //Return response
    return response()->json($response, $status ?? 200);
  }
  
  public function transformSettings(&$mergedSettings, $dbSettings, $assignedSettings){
    $transformedModules = [];
    
    foreach ($mergedSettings as $keyModule => &$module){
      
      foreach ($module as $keySetting => &$setting) {
  
        // name of setting in DB setting
        $dbSettingName = strtolower($keyModule) . '::' . $keySetting;
  
        if (empty($assignedSettings) || in_array($dbSettingName, $assignedSettings)) {
    
          
          if (isset($dbSettings[$keyModule][$dbSettingName])) {
      
            // merging data on DB with config setting
            $settingTransformed = new SettingTransformer($dbSettings[$keyModule][$dbSettingName]);
      
            $setting = array_merge(collect($settingTransformed)->toArray(), $setting);
          } else {
            // init setting value if is file
            if (Str::contains($setting['view'], 'file'))
              $setting['value'] = ['medias_single' => [$dbSettingName => '']];
            else // or plain string
              if (Str::contains($setting['view'], 'select-locales'))
                $setting['value'] = [];
              else
                $setting['value'] = '';
      
            // init setting name
            $setting['name'] = $dbSettingName;
      
          }
    
          if (isset($setting['plainValue'])) {
            // decode plain value if is object or array
            $plainValue = $setting['plainValue'];
            $plainValue = $this->isJson($plainValue) ? json_decode($plainValue) : $plainValue;
      
            // update plain value
            $setting['plainValue'] = $plainValue;
      
            // setting value off settings not translatable
            $setting['value'] = $plainValue;
      
          }
    
          if (isset($setting['description'])) {
            // translate description
            $description = $setting['description'];
            $setting['description'] = trans($description);
          }
    
          if (!isset($setting['isTranslatable'])) {
            $setting['isTranslatable'] = $setting['translatable'] ?? false;
          }
    
    
          // type setting standard based in view param
          $setting['type'] = $setting['view'];
          if (Str::contains($setting['view'], 'select'))
            $setting['type'] = 'select';
    
          // type selectMultiple where view contains select-locale string
          if (Str::contains($setting['view'], 'select-locales'))
            $setting['type'] = 'select-multi';
    
          // init boolean value when type is checkbox
          if ($setting['type'] == 'checkbox' && $setting['value'] == '')
            $setting['value'] = false;
    
          // type setting standard based in view param
          if (Str::contains($setting['view'], 'file'))
            $setting['type'] = 'file';
  
  
          // type setting standard based in view param
          if (Str::contains($setting['view'], 'color'))
            $setting['type'] = 'color';
  
          // type setting standard based in view param
          if (Str::contains($setting['view'], 'text-multi'))
            $setting['type'] = 'text-multi';
  
          // type setting standard based in view param
          if (Str::contains($setting['view'], 'text-multi-with-options'))
            $setting['type'] = 'text-multi-with-options';
    
    
        }else{
          unset($module[$keySetting]);
        }
      }
     
      if (empty($module))
        unset($mergedSettings[$keyModule]);
    }
    
    return $mergedSettings;
  }
  
  
  function isJson($string) {
    return ((is_string($string) &&
      (is_object(json_decode($string)) ||
        is_array(json_decode($string))))) ? true : false;
  }

  
}