<?php
/**
 * Created by PhpStorm.
 * User: TJ 
 * Date: 19/10/18
 * Time: 8:24 PM
 */
namespace App\Http\Controllers;

use App\Libraries\EventCart\Facades\EventCart;
use App\Models\Child;
use App\Models\Event;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class CartController extends Controller
{
    public function add(Request $request, $parentid)
    {
        $parent = User::find($parentid);
        $class = Event::find($request->get('class_id'));
        try {
            EventCart::addClass($class);
        } catch (\Exception $e)
        {
            return back()->withErrors($e->getMessage());
        }
        return redirect()->route('cart', $parent->id);
    }

    public function index($parentid)
    {
        //@todo while visiting cart page, clear out cart for other parents.
        // Cart can only support one parent at a time
        $parent = User::find($parentid);
        $meta = [
            'subtotal' => EventCart::subtotal(),
            'discount' => EventCart::totalDiscount(),
            'total' => EventCart::total(),
            'fee' => EventCart::getTransactionFees(),
        ];
        return view('pages.cart', compact('meta', 'parent'));
    }

    public function autoenroll(Request $request, $parentid)
    {
        $autoenroll = ($request->get('autoenroll') == 'yes');
        $request->session()->put('autoenroll', $autoenroll);
        EventCart::autoEnroll($autoenroll);
        return back();
    }

    public function remove(Request $request, $parentid)
    {
        if($request->has('item_id')){
            try{
                EventCart::remove($request->get('item_id'));
                if(EventCart::content()->count() < 2) {
                    $request->session()->put('autoenroll', false);
                    EventCart::autoEnroll(false); // Remove autoenroll
                }
            } catch (\Exception $e){
                return redirect()->route('cart', $parentid)->withErrors($e->getMessage());
            }
        }
        return redirect()->route('cart', $parentid);
    }

    public function update(Request $request)
    {
        $ignoredChilds = collect([]);
        $event = EventCart::get($request->get('cartitem'));
        if(($event->options['class'] instanceof Event) && (count($request->get('childs')) > 0) && ($event->options['class']->event_age != 'any'))
        {
            $children = Child::find($request->get('childs'));
            $range = explode('-', $event->options['class']->event_age);
            foreach ($children as $child) {
                if(($child->years < $range[0]) || ($child->years > $range[1])){
                    $ignoredChilds[] = $child;
                }
            }
        }

        $allowedChilds = collect($request->get('childs'))->filter()->diff($ignoredChilds->pluck('id'))->all();

        if($request->has('cartitem')){
            EventCart::updateChilds($request->get('cartitem'), $allowedChilds);
        }

        if($ignoredChilds->count() > 0)
        {
            return Redirect::back()->withErrors(implode(', ', $ignoredChilds->pluck('name')->all()) . ' have been prevented from being added to class - '. $event->name . ' as they do not fall in class age group');
        }
        return Redirect::back();
    }
}
