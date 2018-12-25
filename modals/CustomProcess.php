<?php
//###############################################################
//Model Name : CustomProcess
//Author : Bhargav Bhanderi <bhargav@creolestudios.com>
//table : custom_process
//Date : 18th April 2018
//###############################################################
namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class CustomProcess extends Model
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'custom_process';

    /**
     * Fields that can be mass assigned.
     *
     * @var array
     */
    protected $fillable = ['client_id', 'program_id', 'jtbd', 'call_memo', 'contract', 'proposal', 'process', 'process_participats', 'program_status'];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = ['deleted_at'];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['ceated_at', 'updated_at', 'deleted_at'];

    /**
     * The list of validation rules
     *
     * @var array
     */
    public $rules = [
        'client_id'              => 'required',
        'jtbd_file'              => 'sometimes|required|mimes:doc,pdf,docx,xls,xlsx,ppt,pptx',
        'call_memo_file'         => 'sometimes|required|mimes:doc,pdf,docx,xls,xlsx,ppt,pptx',
        'other_document_files.*' => 'sometimes|required|mimes:doc,pdf,docx,ppt,pptx,xls,xlsx',
        'contract_file'          => 'sometimes|required|mimes:doc,pdf,docx,xls,xlsx,ppt,pptx',
        'proposal_file'          => 'sometimes|required|mimes:doc,pdf,docx,xls,xlsx,ppt,pptx',
    ];

    /**
     * CustomProcess has many Activities.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function activities()
    {
        return $this->hasMany('App\CustomActivity', 'process_id');
    }

    /**
     * Query scope active.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('program_status', 2);
    }

    /**
     * Query scope lead.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLead($query)
    {
        return $query->where('program_status', 0);
    }

    /**
     * Query scope leads.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLeads($query)
    {
        return $query->lead()->with(['program', 'client'])->withCount('activities');
    }

    /**
     * Query scope full.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFull($query)
    {
        return $query->with(['program', 'client', 'activities.documents']);
    }

    /**
     * Query scope tentative.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTentative($query)
    {
        return $query->where('program_status', 1);
    }

    /**
     * Query scope completed.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('program_status', 3);
    }

    /**
     * CustomProcess belongs to Client.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo('App\Clients', 'client_id');
    }

    /**
     * CustomProcess belongs to Program.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function program()
    {
        return $this->belongsTo('App\CustomPrograms', 'program_id');
    }

    /**
     * CustomProcess has many Batches.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function batches()
    {
        return $this->hasMany('App\CustomBatches', 'process_id');
    }

    /**
     * CustomProcess has many sessioncount through Throughs.
     * Works for 1-1/1-m through 1-1/1-m
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function sessioncount()
    {
        return $this->hasManyThrough('App\CustomSessions', 'App\CustomBatches', 'process_id', 'batch_id');
    }

    /**
     * CustomProcess has many Sessions through Throughs.
     * Works for 1-1/1-m through 1-1/1-m
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function sessions()
    {
        return $this->hasManyThrough('\App\CustomSessions', '\App\CustomBatches', 'process_id', 'batch_id');
    }

    /**
     * CustomProcess has one Link.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function link()
    {
        return $this->hasOne('App\CustomLink', 'process_id');
    }

    /**
     * CustomProcess has many Participants.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function participants()
    {
        return $this->hasMany('App\CustomParticipants', 'process_id');
    }

    /**
     * CustomProcess has many Graduated.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function graduated()
    {
        return $this->hasMany('App\CustomParticipants', 'process_id')->where('attendance','>',79);
    }

    /**
     * CustomProcess has many Stakeholders.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stakeholders()
    {
        return $this->hasMany('App\CustomStakeholders', 'process_id')->with(['stakeholder.designations']);
    }

    /**
     * CustomProcess has many Stakeholder.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stakeholder()
    {
        return $this->hasMany('App\CustomStakeholders', 'process_id');
    }

    /**
     * CustomProcess has many thismonth through Throughs.
     * Works for 1-1/1-m through 1-1/1-m
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function thismonth()
    {
        return $this->hasManyThrough('App\CustomSessions', 'App\CustomBatches', 'process_id', 'batch_id')->whereMonth('start_date', Carbon::now()->format('m'))->whereYear('start_date', Carbon::now()->format('Y'));
    }

    /**
     * CustomProcess has many Documents through Throughs.
     * Works for 1-1/1-m through 1-1/1-m
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function documents()
    {
        return $this->hasManyThrough('App\CustomActivityDocuments', 'App\CustomActivity', 'process_id', 'activity_id');
    }

    /**
     * Query scope activedata.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActivedata($query)
    {
        return $query->with(['client', 'participants', 'program'])->withCount('thismonth');
    }

    /**
     * Query scope clientdata.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeClientdata($query)
    {
        return $query->with(['program', 'participants']);
    }

    /**
     * Query scope Withoutlead.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithoutlead($query)
    {
        return $query->where('program_status', '!=', 0);
    }

    /**
     * Query scope alldata.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAlldata($query)
    {
        return $query->with(['activities.documents', 'program' => function ($query) {
            $query->with(['stakeholder', 'modules'])->withCount('modulecount');
        }, 'client', 'link', 'stakeholders', 'participants', 'batches' => function ($query) {
            $query->with(['sessions' => function ($query) {
                $query->with(['tasks','attendance.customparticipant'])->withCount(['unconfirmed', 'confirmed', 'modules', 'pending']);
            }])->withCount(['tasks', 'unconfirmed', 'confirmed', 'modules', 'pending']);
        }]);
    }

    /**
     * Query scope oopdata.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOopdata($query, $process_id, $batch_id, array $parentIds)
    {
        return $query->where('id', $process_id)->with(['program.modules' => function ($query) use ($parentIds) {
            $query->whereNotIn('id', $parentIds);
        }, 'batches' => function ($query) use ($batch_id) {
            $query->where('id', $batch_id);
        }, 'stakeholders'])->withCount('sessioncount')->first();
    }


    /**
     * CustomProcess has many FilledForms through Throughs.
     * Works for 1-1/1-m through 1-1/1-m
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function filledForms()
    {
        return $this->hasManyThrough('App\FormDataEntries', 'App\CustomParticipants', 'process_id','participant_id')->where('process_type', 3);
    }

    /**
     * InhouseProcess has many ProgramForms through Throughs.
     * Works for 1-1/1-m through 1-1/1-m
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function programForms()
    {
        return $this->hasManyThrough('App\FormDataEntries', 'App\CustomParticipants', 'process_id','participant_id');
    }


    /**
     * CustomProcess has many Entries.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function entries()
    {
        return $this->hasMany('App\FormDataEntries', 'process_id')->where('process_type', 3);
    }
}
