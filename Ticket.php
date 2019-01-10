<?php

/**
 * Created by PhpStorm.
 * User: TJ 
 * Date: 18/10/18
 * Time: 5:21 PM
 */
 
namespace App;
use App\BaseModel;

class Ticket extends BaseModel
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'tickets';

    /**
     * The database primary key value.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
	 
    protected $fillable = ['title','site_id', 'subject_id', 'content', 'status', 'user_id', '_website_id'];

    protected $hidden = [
        'deleted_at', 'created_by', 'updated_by'
    ];


	protected $appends = ['is_editable','total_files','created'];
	
	public function scopeActive($q)
    {
        return $q->where('active', true);
    }
	public function getCreatedAttribute()
    {
        if($this->created_at != "" && $this->created_at){
            return \Carbon\Carbon::parse($this->created_at)->format(session('setting.date_format',\config('settings.date_format_on_app')));
        }
        return $this->created_at;
    }
	
    public static function getStatus()
    {
        return [
            'new' => 'New','open' => 'Open','closed' => 'Closed'
        ];
    }
	public function user()
    {
        return $this->belongsTo('App\User');
    }
	public function comments()
    {
        return $this->hasMany('App\TicketHistory','ticket_id', 'id')->with('user')->where('history_type','comment');
    }
    public function next(){
		return Ticket::where('id', '>', $this->id)->orderBy('id','asc')->first();
	}
    public  function previous(){
        return Ticket::where('id', '<', $this->id)->orderBy('id','desc')->first();

    }
}
