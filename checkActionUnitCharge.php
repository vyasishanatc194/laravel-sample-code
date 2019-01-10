<?php
/**
 * Created by PhpStorm.
 * User: TJ 
 * Date: 18/10/18
 * Time: 5:02 PM
 */
namespace App\Http\Middleware;
use App\UnitsModule;
use Closure;
use Session;

class checkActionUnitCharge
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $route_name =  \Request::route()->getName();
        $free_licence = \config('settings.free_license');
        $unit_charge_route =  \config('settings.unit_charge_route');

        if(!_MASTER && _ENABLE_CHARGE){
            foreach($unit_charge_route as $k=>$info){
               
                foreach($info as $i=>$in){
                    if($in==$route_name){
                        $unitsModule = UnitsModule::where("action",$k)->first();
                        if($unitsModule && _WEBSITE_UNITS < $unitsModule->unit){
                            return response(view('auth.accessDenied',['reasion'=>'insufficient_unit']));
                        }else if($unitsModule && $unitsModule->unit > 0){
                            $h2 = \Lang::get('unittransaction.notification.domin_will_be_charge_per_day',['units'=>$unitsModule->unit]);
                            if(isset($free_licence[$k]) && $free_licence[$k] >0){
                               $h2= $h2." ".\Lang::get('unittransaction.notification.allow_free_tiems',['module_name'=>$k,'total_free'=>$free_licence[$k]]);
                            }
                            Session::flash('form_unit_charge_notification',$h2);
                        }
                    }
                }
            }
        }
        return $next($request);
    }
}
