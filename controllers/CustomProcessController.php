<?php
/**
 * Short description for file
 *
 * PHP version 5 and 7
 *
 * LICENSE: This source file is subject to version 3.01 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_01.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @package    OpenhouseprocessController
 * @author     Bhargav Bhanderi (bhargav@creolestudios.com)
 * @copyright  2017 Raiser Group
 * @license    http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version    SVN Branch: backend_api_controller
 * @since      File available since Release 1.0.0
 * @deprecated N/A
 */
/*
 * Custom Programs section related controller
 */

/**
Pre-Load all necessary library & name space
 */
namespace App\Http\Controllers;

use App\CustomActivity;
use App\CustomBatches;
use App\CustomLink;
use App\CustomParticipants;
use App\CustomProcess;
use App\CustomPrograms;
use App\CustomProgramsStakeholders;
use App\CustomSessions;
use App\CustomSessionTasks;
use App\Http\Controllers\UtilityController;
use App\Mail\LinkGenerated;
use App\Mail\ModuleRequestMail;
use App\Mail\TaskAssignedMail;
use App\PlanSessionModules;
use App\Programs;
use App\StakeholdersLinks;
use App\SystemNotifications;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mail;
use View;

class CustomprocessController extends Controller
{

    //###############################################################
    //Function Name : Initiatecustomlead
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to initiate new custom lead
    //In Params : void
    //Date : 18th April 2018
    //###############################################################

    public function Initiatecustomlead(Request $request)
    {
        try {
            ## Begin transaction from here
            \DB::beginTransaction();
            $returnData = UtilityController::Setreturnvariables();
            ## check empty request
            if ($request) {
                $modulesList = [];
                $valid       = 1;
                if (is_array($request->modules)) {
                    /**
                     *
                     * modify moduels list from data we have recived
                     * @return Array
                     */
                    $modulelist = $this->Getmodulelist($request->modules);
                    ## continue process if modules list is't empty else pass went wrong message
                    if (!empty($modulelist)) {
                        ## make modules in collection that we can filter the name ##
                        $modules = collect($modulelist);
                        $list    = $modules->map(function ($module, $key) {
                            $module['name'] = "Module #" . ($key + 1);
                            return $module;
                        })->toArray();
                        ## validate main program data
                        $returnData = UtilityController::ValidationRules($request->all(), 'CustomPrograms');
                        if ($returnData['status']) {
                            ## if main data is been validated, validate it's modules
                            $returnData = UtilityController::ValidationRules($list, 'CustomSessionModules');
                        }
                        ## if above requests succeed, continue process.
                        if ($returnData['status']) {
                            ## create custom program.
                            $customProgram = CustomPrograms::create($request->all());
                            ## create program modules with referance of custom program
                            $modules = $customProgram->modules()->createMany($list);
                            if (!$customProgram || !$modules) {
                                ## valid flag to track everything worked fine
                                $valid = 0;
                            }
                            ## collect activity
                            $activity     = request('activity');
                            $withActivity = 0;
                            $client       = $request->client_id;
                            if (!empty($activity)) {
                                ## if comment passed with activity, than only set flag to one.
                                ## no one will be able to move only slider. comment is compulsary.
                                $withActivity = isset($activity['comment']) ? 1 : 0;
                            }
                            ## validate custom process data.
                            $returnData = UtilityController::ValidationRules($request->all(), 'CustomProcess');
                            if ($returnData['status']) {
                                ## create new lead.
                                $newLead = $customProgram->process()->create($request->all());
                                if (!$newLead) {
                                    ## passed valid flag
                                    $valid = 0;
                                }
                                if ($newLead && $request->allFiles()) {
                                    /**
                                     *
                                     * to check wether request have above file,
                                     * if available upload and store, else skip.
                                     *
                                     */
                                    if ($request->hasFile('jtbd_file')) {
                                        $jtbd               = $this->Uploadfiles('jtbd', $request->file('jtbd_file'), $client, $newLead->id);
                                        $updateData['jtbd'] = $jtbd;
                                    }
                                    if ($request->hasFile('call_memo_file')) {
                                        $call_memo               = $this->Uploadfiles('call_memo', $request->file('call_memo_file'), $client, $newLead->id);
                                        $updateData['call_memo'] = $call_memo;
                                    }
                                    if ($request->hasFile('contract_file')) {
                                        $contract               = $this->Uploadfiles('contract', $request->file('contract_file'), $client, $newLead->id);
                                        $updateData['contract'] = $contract;
                                    }
                                    if ($request->hasFile('proposal_file')) {
                                        $proposal               = $this->Uploadfiles('proposal', $request->file('proposal_file'), $client, $newLead->id);
                                        $updateData['proposal'] = $proposal;
                                    }
                                    if ($withActivity == 1 && !empty($activity['other_document_files'])) {
                                        $other_documents = $this->Uploadfiles('other_documents', $activity['other_document_files'], $client, $newLead->id);
                                        unset($activity['other_document_files']);
                                        $activity['documents'] = $other_documents;
                                    }
                                }

                                if ($withActivity == 1) {
                                    $activity['percent_at'] = $activity['percent'];
                                    $newActivity            = $newLead->activities()->create($activity);
                                    if (isset($activity['documents'])) {
                                        $createActivity = $newActivity->documents()->createMany($activity['documents']);
                                        if (!$createActivity) {
                                            $valid = 0;
                                        }
                                    }
                                    if (!$newActivity) {
                                        $valid = 0;
                                    }
                                }

                                if (!empty($updateData)) {
                                    $update = $newLead->update($updateData);
                                    if (!$update) {
                                        $valid = 0;
                                    }
                                }

                                if ($valid) {
                                    $returnData = UtilityController::Generateresponse(1, 'LEAD_GENERATED', 200, $newLead);
                                    \DB::commit();
                                }
                            }

                        }

                    }
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomlist
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get program list of custom program by type
    //In Params : void
    //Date : 18th April 2018
    //###############################################################

    public function Getcustomlist($type)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $type       = base64_decode($type);
            if ($type == 0) {
                ## lead
                $processList = CustomProcess::leads()->get();

            } elseif ($type == 1) {
                ## tentative
                $processList = CustomProcess::tentative()->countdata()->get();

            } elseif ($type == 2) {
                ## active
                $processList = CustomProcess::active()->activedata()->get();
            } elseif ($type == 3) {
                $processList = CustomProcess::completed()->activedata()->get();

            }
            foreach ($processList as $key => $value) {
                $processList[$key]['files'] = UtilityController::customFiles($processList[$key]['client_id'], $processList[$key]['id']);
                // $leads[$key]['files'] = UtilityController::files($leads[$key]['client_id'], $leads[$key]['program_id']);
            }

            $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $processList);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomlead
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get custom lead data
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Getcustomlead($processId)
    {
        try {
            $returnData   = UtilityController::Setreturnvariables();
            $processId    = base64_decode($processId);
            $responseData = CustomProcess::full()->lead()->find($processId);
            $returnData   = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $responseData);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Downloadcustomfile
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to download particular file regarding custom programs
    //In Params :
    //Date : 30th April 2018
    //###############################################################

    public function Downloadcustomfile($client, $program, $type, $name)
    {
        try {
            $client  = base64_decode($client);
            $program = base64_decode($program);
            $file    = "$client/$program/$type/$name";
            if (Storage::disk('custom')->has($file)) {
                $path = Storage::disk('custom')->path($file);
                return response()->download($path);
            } else {
                return redirect()->back();
            }

        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return redirect()->back();
        }
    }

    //###############################################################
    //Function Name : Addcustomactivity
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to add new custom activity
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Addcustomactivity(Request $request)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::Setreturnvariables();
            $client     = $request->client_id;
            $program    = $request->id;
            $activity   = $request->activity;
            $returnData = UtilityController::ValidationRules($activity, 'CustomActivity');
            if ($returnData['status']) {
                if (!empty($activity['other_document_files'])) {
                    $other_documents = $this->Uploadfiles('other_documents', $activity['other_document_files'], $client, $program);
                    unset($activity['other_document_files']);
                    $activity['documents'] = $other_documents;
                }
                $activity['percent']    = ($activity['percent'] - $request->percent);
                $activity['percent_at'] = ($activity['percent'] + $request->percent);
                $custom                 = CustomProcess::find($request->id);
                $newActivity            = $custom->activities()->create($activity);
                if (!empty($activity['documents'])) {
                    $newActivity->documents()->createMany($activity['documents']);
                }
                $proces = CustomProcess::find($request->id);
                if ($newActivity) {
                    $returnData = UtilityController::Generateresponse(1, 'ACTIVITY_CREATED', 200, $proces->percent);
                    \DB::commit();
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Deletecustomactivity
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to delete particular activity and it's documents
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Deletecustomactivity($activityId)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::Setreturnvariables();
            $activityId = base64_decode($activityId);
            $activity   = CustomActivity::find($activityId);
            if ($activity) {
                $process = CustomProcess::select('client_id', 'program_id')->find($activity->process_id);
                $client  = $process->client_id;
                $program = $process->id;
                $files   = $activity->documents->pluck('name')->toArray();
                if ($files) {
                    $filesDeleted = $this->Deletefile('other_documents', $files, $client, $program);
                    if (!$filesDeleted) {
                        return $returnData;
                    }
                }
                if ($activity->delete()) {
                    $returnData = UtilityController::Generateresponse(1, 'ACTIVITY_DELETED', 200, '');
                    \DB::commit();
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Updatecustomlead
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to update custom lead
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Updatecustomlead(Request $request)
    {
        try {
            \DB::beginTransaction();
            ## default return data
            $returnData = UtilityController::Setreturnvariables();
            $returnData = UtilityController::ValidationRules($request->all(), 'CustomProcess');
            if ($returnData['status']) {
                $activity = request('activity');
                ## check if have jtbd file
                if ($request->hasFile('jtbd_file')) {
                    if ($request->jtbd) {
                        $this->Deletefile('jtbd', $request->jtbd, $request->client_id, $request->id);
                    }
                    $jtbd = $this->Uploadfiles('jtbd', $request->file('jtbd_file'), $request->client_id, $request->id);
                    $request->merge(['jtbd' => $jtbd]);
                }
                ## check if have call memo file
                if ($request->hasFile('call_memo_file')) {
                    if ($request->call_memo) {
                        $this->Deletefile('call_memo', $request->call_memo, $request->client_id, $request->id);
                    }
                    $call_memo = $this->Uploadfiles('call_memo', $request->file('call_memo_file'), $request->client_id, $request->id);
                    $request->merge(['call_memo' => $call_memo]);
                }
                ## check if have contract file
                if ($request->hasFile('contract_file')) {
                    if ($request->contract) {
                        $this->Deletefile('contract', $request->contract, $request->client_id, $request->id);
                    }
                    $contract = $this->Uploadfiles('contract', $request->file('contract_file'), $request->client_id, $request->id);
                    $request->merge(['contract' => $contract]);
                }
                ## check if have proposal file
                if ($request->hasFile('proposal_file')) {
                    if ($request->proposal) {
                        $this->Deletefile('proposal', $request->proposal, $request->client_id, $request->id);
                    }
                    $proposal = $this->Uploadfiles('proposal', $request->file('proposal_file'), $request->client_id, $request->id);
                    $request->merge(['proposal' => $proposal]);
                }
                $leadData = UtilityController::Makemodelobject($request->all(), 'CustomProcess', 'id', $request->id);
                if (!empty($activity) && isset($activity['comment'])) {
                    $returnData = UtilityController::ValidationRules($activity, 'CustomActivity');
                    if ($returnData['status']) {
                        $returnData = $this->Addcustomactivity($request);
                        if (!$returnData['status']) {
                            return $returnData;
                        }
                    } else {
                        return $returnData;
                    }
                }
                if ($leadData) {
                    \DB::commit();
                    $returnData = UtilityController::Generateresponse(1, 'LEAD_UPDATED', 200, $leadData);
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomtentative
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : get tentative program data by it's id
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Getcustomtentative($processId)
    {
        try {
            $returnData   = UtilityController::Setreturnvariables();
            $processId    = base64_decode($processId);
            $responseData = CustomProcess::tentative()->alldata()->find($processId);
            $modules      = $responseData->program->modules->groupBy('c_program_id')->toArray();
            $data         = [];
            foreach ($modules as $key => $value) {
                $program             = \App\Programs::find($key);
                $programName         = $program->title . ' (' . $program->program_type['type'] . ')';
                $response['program'] = $programName;
                $response['modules'] = count($value);
                array_push($data, $response);
            }
            $responseData                   = $responseData->toArray();
            $responseData['module_records'] = $data;
            $returnData                     = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $responseData);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Generatecustomlink
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to generate add participant link for custom program
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Generatecustomlink($clientId, $processId)
    {
        try {
            $ProcessId = base64_decode($processId);
            ## default return data
            $returnData = UtilityController::Setreturnvariables();
            ## create link and store into database
            $available = CustomLink::where('client_id', base64_decode($clientId))->where('process_id', base64_decode($processId))->delete();
            $link      = $this->Createlink($clientId, $processId);
            ## get response data
            $linkData = $link['data'];
            ## get related data
            // $data       = $linkData->with(['client', 'process'])->find($linkData->id);
            $data = CustomLink::with(['client', 'process'])->find($linkData->id);

            $program     = CustomProcess::find($ProcessId)->program;
            $programName = $program->title . "(Custom)";

            $contactPersons = \App\ClientsContactInfo::where('client_id', $data->client->id)->get()->toArray();
            if (!empty($contactPersons)) {
                foreach ($contactPersons as $key => $value) {
                    $record                 = $data->toArray();
                    $record['person_name']  = $value['poc_name'];
                    $record['program_name'] = $programName;
                    Mail::to($value['email'])->later(Carbon::now(), new LinkGenerated($record));
                    $returnData = UtilityController::Generateresponse(1, 'LINK_GENERATED', 200, $record);
                }
            } else {
                $returnData = UtilityController::Generateresponse(0, 'NO_POC', '', '');
            }
            ## return response
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : LinkCustomParticipants
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to access link of custom program
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function LinkCustomParticipants($clientId, $processId)
    {
        try {
            $clientId  = base64_decode($clientId);
            $processId = base64_decode($processId);
            $today     = Carbon::now()->toDateTimeString();
            $link      = CustomLink::where('client_id', $clientId)->where('process_id', $processId)->first();
            if (!$link) {
                return View::make('notice')->with('message', config('constants.links.wrong'));
            }
            $expires = $link->expires_at;
            if ($link && $link->status == 1 && $today <= $expires) {
                return View::make('customparticipants')->with('link', $link);
            } else {
                if ($link->status == 0) {
                    $message = config('constants.links.added');
                } elseif ($today > $expires) {
                    $message = config('constants.links.expired');
                } else {
                    $message = config('constants.links.wrong');
                }
                return View::make('notice')->with('message', $message);
            }
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomsessionbatch
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get custom package batch
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Getcustomsessionbatch($processId)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $processId  = base64_decode($processId);
            $batches    = CustomBatches::where('process_id', $processId)->get();
            if ($batches) {
                $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $batches);
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Addcustombatches
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to add custom batches
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Addcustombatches(Request $request)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::Setreturnvariables();
            if ($request) {
                $batches = UtilityController::Savearrayobject($request->all(), 'CustomBatches');
                if ($batches) {
                    $returnData = UtilityController::Generateresponse(1, 'BATCH_CREATED', 200, $batches->toArray());
                    \DB::commit();
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Addcustomparticipantlist
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to add custom participants
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Addcustomparticipantlist(Request $request)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::ValidationRules($request->all(), 'CustomParticipants', ['batch_id']);
            if ($returnData['status']) {
                $link         = CustomLink::where('client_id', $request->client_id)->where('process_id', $request->process_id)->first();
                $link->status = 0;
                $link->save();
                $participants    = collect(request('participants'));
                $participantlist = $participants->map(function ($data) {
                    $data['level']      = 1;
                    $data['process_id'] = request('process_id');
                    return $data;
                });
                if ($participantlist) {
                    $insert = CustomParticipants::insert($participantlist->toArray());
                    if ($insert) {
                        ## commit transaction if all above transactions fullfilled and modify response
                        \DB::commit();
                        $returnData = UtilityController::Generateresponse(1, 'PARTICIPANTS_ADDED', 200, '');
                    }
                }
            }
            ## return response
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Changecustombatches
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to change batch of participant
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Changecustombatches(Request $request)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::Setreturnvariables();
            if ($request) {
                $updateParticipants = UtilityController::Createupdatearray($request->all(), 'CustomParticipants');
                if ($updateParticipants) {
                    \DB::commit();
                    $returnData = UtilityController::Generateresponse(1, 'PARTICIPANTS_UPDATED', 200, $updateParticipants);
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Addnewcustombatch
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to add new custom program batch
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Addnewcustombatch($processId)
    {
        try {
            \DB::beginTransaction();
            $returnData          = UtilityController::Setreturnvariables(); ## default return data
            $processId           = base64_decode($processId); ## decode process id
            $batch               = []; ## create a batch array
            $process             = CustomProcess::find($processId); ## find proces
            $batchesCount        = CustomBatches::where('process_id', $processId)->count(); ## get count of batches
            $batch['name']       = 'Batch #' . ($batchesCount + 1); ## generate new batch name
            $batch['process_id'] = $processId; ## get process id of batch
            $batch['client_id']  = $process->client_id; ## get client id of batch
            $createBatch         = CustomBatches::create($batch); ## create new batch

            if ($createBatch) {
                \DB::commit();
                ## if batch is created, modify response
                $returnData = UtilityController::Generateresponse(1, 'NEW_BATCH_CREATED', 200, $createBatch);
            }
            ## return response
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomoopdata
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get custom order of play data
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    public function Getcustomoopdata($processID, $batchId)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $processID  = base64_decode($processID);
            $batchId    = base64_decode($batchId);
            if (!empty($processID) && !empty($batchId)) {
                $getModules = CustomBatches::find($batchId);
                $parentIds  = $getModules->modules->pluck('parent_id')->toArray();
                $oopData    = CustomProcess::oopdata($processID, $batchId, $parentIds)->toArray();
                if ($oopData) {
                    $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $oopData);
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Updatecustomprocess
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to update custom process data
    //In Params : void
    //Date : 1st May 2018
    //###############################################################

    public function Updatecustomprocess(Request $request)
    {
        try {
            \DB::beginTransaction();
            ## default return data
            $returnData = UtilityController::Setreturnvariables();
            $returnData = UtilityController::ValidationRules($request->all(), 'CustomProcess');
            if ($returnData['status']) {
                $activity = request('activity');
                ## check if have jtbd file
                if ($request->hasFile('jtbd_file')) {
                    if ($request->jtbd) {
                        $this->Deletefile('jtbd', $request->jtbd, $request->client_id, $request->id);
                    }
                    $jtbd = $this->Uploadfiles('jtbd', $request->file('jtbd_file'), $request->client_id, $request->id);
                    $request->merge(['jtbd' => $jtbd]);
                }
                ## check if have call memo file
                if ($request->hasFile('call_memo_file')) {
                    if ($request->call_memo) {
                        $this->Deletefile('call_memo', $request->call_memo, $request->client_id, $request->id);
                    }
                    $call_memo = $this->Uploadfiles('call_memo', $request->file('call_memo_file'), $request->client_id, $request->id);
                    $request->merge(['call_memo' => $call_memo]);
                }
                ## check if have contract file
                if ($request->hasFile('contract_file')) {
                    if ($request->contract) {
                        $this->Deletefile('contract', $request->contract, $request->client_id, $request->id);
                    }
                    $contract = $this->Uploadfiles('contract', $request->file('contract_file'), $request->client_id, $request->id);
                    $request->merge(['contract' => $contract]);
                }
                ## check if have proposal file
                if ($request->hasFile('proposal_file')) {
                    if ($request->proposal) {
                        $this->Deletefile('proposal', $request->proposal, $request->client_id, $request->id);
                    }
                    $proposal = $this->Uploadfiles('proposal', $request->file('proposal_file'), $request->client_id, $request->id);
                    $request->merge(['proposal' => $proposal]);
                }
                $leadData = UtilityController::Makemodelobject($request->all(), 'CustomProcess', 'id', $request->id);
                if ($leadData) {
                    if (!empty($request->stakeholdersdata)) {
                        $returnData = $this->AddUpdateStakeholders($request->stakeholdersdata);
                        if (!$returnData['status']) {
                            return $returnData;
                        }
                    }
                }
                if (!empty($activity) && isset($activity['comment'])) {
                    $returnData = UtilityController::ValidationRules($activity, 'CustomActivity');
                    if ($returnData['status']) {
                        $returnData = $this->Addcustomactivity($request);
                        if (!$returnData['status']) {
                            return $returnData;
                        }
                    } else {
                        return $returnData;
                    }
                }
                if ($leadData) {
                    \DB::commit();
                    $returnData = UtilityController::Generateresponse(1, 'LEAD_UPDATED', 200, $leadData);
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Submitcustomoop
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to submit order of play
    //In Params : void
    //Date : 2th May 2018
    //###############################################################

    public function Submitcustomoop(Request $request)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::ValidationRules($request->all(), 'CustomSessions');
            if ($returnData['status']) {
                $createSession = CustomSessions::create($request->all());
                if ($createSession) {
                    if ($request->get('pre')) {
                        $returnData = UtilityController::ValidationRules($request->get('pre'), 'CustomSessionTasks');
                    }
                    if ($returnData['status']) {
                        if ($request->get('post')) {
                            $returnData = UtilityController::ValidationRules($request->get('post'), 'CustomSessionTasks');
                        }
                        if ($returnData['status']) {
                            $returnData = UtilityController::ValidationRules($request->get('plan'), 'CustomSessionTasks');
                        }
                    }
                    if ($returnData['status']) {
                        $postData = $request->post;
                        $preData  = $request->pre;
                        $planData = $request->plan;
                        if (!empty($preData)) {
                            $preData = $this->Findfileandsave($preData);
                        }
                        if (!empty($postData)) {
                            $postData = $this->Findfileandsave($postData);
                        }
                        if (!empty($planData)) {
                            $planData = $this->Findfileandsave($planData);
                        }

                        $pre  = collect($preData);
                        $post = collect($postData);
                        $plan = collect($planData);
                        $plan = $plan->map(function ($task) {
                            $task['task_type']  = 2;
                            $task['batch_id']   = request('batch_id');
                            $task['creator_id'] = \Auth::user()->id;
                            return $task;
                        });
                        $plan = $plan->filter(function ($value, $key) {
                            return $value != 'null';
                        });
                        if (!$pre->isEmpty()) {
                            $pre = $pre->map(function ($pre) {
                                $pre['task_type']  = 1;
                                $pre['batch_id']   = request('batch_id');
                                $pre['creator_id'] = \Auth::user()->id;
                                return $pre;
                            })->toArray();

                            $ordering = 1;
                            foreach ($pre as $key => $value) {
                                $value['ordering'] = $ordering;

                                $pretasks  = $createSession->tasks()->create($value);
                                $taskvalue = $pretasks->toArray();
                                $this->Informstakeholder($taskvalue, $request->batch_id);

                                $ordering++;
                            }

                            // $pretasks = $createSession->tasks()->createManyOrders($pre->toArray());
                        }
                        if (!$post->isEmpty()) {
                            $post = $post->map(function ($task) {
                                $task['task_type']  = 3;
                                $task['batch_id']   = request('batch_id');
                                $task['creator_id'] = \Auth::user()->id;
                                return $task;
                            })->toArray();
                            // $posttasks = $createSession->tasks()->createManyOrders($post->toArray());
                            $ordering = 1;
                            foreach ($post as $key => $value) {
                                $value['ordering'] = $ordering;

                                $posttasks = $createSession->tasks()->create($value);
                                $taskvalue = $posttasks->toArray();
                                $this->Informstakeholder($taskvalue, $request->batch_id);

                                $ordering++;
                            }
                        }

                        $combinedDate = date('Y-m-d H:i:s', strtotime("$createSession->start_date $createSession->start_time"));
                        $sessionDate  = new Carbon($combinedDate) /*Carbon::parse($combinedDate)*/;
                        $taskDuration = $sessionDate;
                        ## loop to assign time based on duration
                        $order = 1;
                        foreach ($plan->toArray() as $key => $value) {
                            $newDate               = new Carbon($combinedDate);
                            $value['ordering']     = $order;
                            $value['status']       = 0;
                            $value['is_confirmed'] = 0;
                            if (isset($value['trigger_action']) && $value['trigger_action'] != null && $value['trigger_action'] != '' && $value['trigger_action'] != 'null') {
                                if ($value['trigger_action'] == 0) {
                                    ## after task complete
                                    $value['fire_at'] = $taskDuration;
                                } elseif ($value['trigger_action'] == 1) {
                                    ## before session starts
                                    $subMinute        = new Carbon($newDate->subMinute(5));
                                    $value['fire_at'] = $subMinute;
                                } elseif ($value['trigger_action'] == 2) {
                                    ## exact time
                                }
                            }
                            if (!isset($value['fire_at']) || $value['fire_at'] == null || $value['fire_at'] == 'null' || $value['fire_at'] == '') {
                                $value['fire_at'] = $taskDuration;
                            }
                            $intasks = $createSession->tasks()->create($value);

                            $taskvalue = $intasks->toArray();
                            $this->Informstakeholder($taskvalue, $request->batch_id);
                            if (isset($value['duration']) && $value['duration'] != null) {
                                $durations = explode(':', $value['duration']);
                                $taskDuration->addHour($durations['0']);
                                $taskDuration->addMinute($durations['1']);
                            }
                            $order++;
                        }

                        if ($intasks) {
                            $createSession->end_time = $taskDuration->toTimestring();
                            $createSession->save();
                            $returnData = UtilityController::Generateresponse(1, 'OOP_SET', 200, '');
                            \DB::commit();
                        }
                    }

                }
            }

            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Cloneallbatches
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to clone batches
    //In Params : void
    //Date : 2th May 2018
    //###############################################################

    public function Cloneallbatches($batchId)
    {
        try {
            \DB::beginTransaction();
            $returnData       = UtilityController::Setreturnvariables();
            $batchId          = base64_decode($batchId);
            $recorDs          = CustomBatches::with(['sessions.tasks'])->find($batchId)->toArray();
            $processId        = $recorDs['process_id'];
            $allCustomBatches = CustomBatches::where('process_id', $processId)->where('id', '!=', $batchId)->get()->toArray();
            $valid            = true;
            if (!empty($allCustomBatches)) {
                foreach ($allCustomBatches as $batchKey => $batchValue) {
                    $batch = CustomBatches::find($batchValue['id']);
                    $batch->sessions()->delete();
                    foreach ($recorDs['sessions'] as $key => $value) {
                        $session = $batch->sessions()->create($value);
                        if ($session) {
                            $tasks = $session->tasks()->createMany($value['tasks']);
                            if (!$tasks) {
                                $valid = false;
                            }
                        } else {
                            $valid = false;
                        }
                    }
                }
                if ($valid) {
                    \DB::commit();
                    $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, '');
                }
            } else {
                $returnData = UtilityController::Generateresponse(0, 'NO_MORE_BATCHES', 200, '');
            }
            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Donecustomoop
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to send dc a confirmation mail
    //In Params : void
    //Date : 2th May 2018
    //###############################################################

    public function Donecustomoop($processId)
    {
        try {
            \DB::beginTransaction();
            $returnData   = UtilityController::Setreturnvariables();
            $process      = base64_decode($processId);
            $type         = base64_encode(3);
            $stakeholders = CustomProcess::consultants()->where('custom_process.id', $process)->get()->toArray();
            if (!empty($stakeholders)) {
                foreach ($stakeholders as $key => $stakeholder) {
                    $holderId            = base64_encode($stakeholder['id']);
                    $stakeholder['link'] = url("/confirmation/$processId/$holderId/$type");
                    ## generate link for stakeholder
                    $oldLinks             = StakeholdersLinks::where('process_id', $process)->where('stackholder_id', $stakeholder['id'])->where('type', 3)->delete();
                    $link                 = new StakeholdersLinks;
                    $link->type           = 3;
                    $link->process_id     = $process;
                    $link->stackholder_id = $stakeholder['id'];
                    $link->expires_at     = Carbon::now()->addDays(2)->toDateTimestring();
                    $link->save();
                    Mail::to($stakeholder['email'])->later(Carbon::now(), new ModuleRequestMail($stakeholder));
                }
                \DB::commit();
                $returnData = UtilityController::Generateresponse(1, 'CONFIRMATION_SENT', 200, '');
            } else {
                $returnData = UtilityController::Generateresponse(2, 'NO_PENDING_MODULES', 200, '');
            }
            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomoopupdatedata
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get update oop data
    //In Params : void
    //Date : 2nd May 2018
    //###############################################################

    public function Getcustomoopupdatedata($process_id, $batch_id, $session_id)
    {
        try {
            ## set default response
            $returnData = UtilityController::Setreturnvariables();
            ## decode process id
            $process_id = base64_decode($process_id);
            ## decode session id
            $session_id = base64_decode($session_id);
            ## decode batch id
            $batch_id = base64_decode($batch_id);
            ## find process batch id from plan session id and batch id
            if ($session_id && $batch_id && $process_id) {
                ## find process
                $process = CustomProcess::with(['batches' => function ($query) use ($batch_id, $session_id) {
                    $query->with(['sessions' => function ($query) use ($session_id) {
                        $query->with('tasks')->where('id', $session_id);
                    }])->where('id', $batch_id);
                }])->with(['program' => function ($query) {
                    $query->select('id', 'title', 'program_type');
                }])->with('stakeholders')->find($process_id);

                ## if data found, modify response
                if ($process) {
                    $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $process);
                }
            }

            ## return response
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Updatecustomoop
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to update custom program irder of play
    //In Params : void
    //Date : 2th May 2018
    //###############################################################

    public function Updatecustomoop(Request $request)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::Setreturnvariables();
            $session    = CustomSessions::find($request->session_id);
            if ($session) {
                $session->update($request->all());
                if ($request->get('pre')) {
                    $returnData = UtilityController::ValidationRules($request->get('pre'), 'CustomSessionTasks');
                } else {
                    $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', '', '');
                }
                if ($returnData['status']) {
                    if ($request->get('post')) {
                        $returnData = UtilityController::ValidationRules($request->get('post'), 'CustomSessionTasks');
                    }
                    if ($returnData['status']) {
                        $returnData = UtilityController::ValidationRules($request->get('plan'), 'CustomSessionTasks');
                    }
                }
                if ($returnData['status']) {
                    $returnData = UtilityController::Setreturnvariables();
                    $postData   = $request->post;
                    $preData    = $request->pre;
                    $planData   = $request->plan;
                    if (!empty($preData)) {
                        $preData = $this->Findfileandsave($preData);
                    }
                    if (!empty($postData)) {
                        $postData = $this->Findfileandsave($postData);
                    }
                    if (!empty($planData)) {
                        $planData = $this->Findfileandsave($planData);
                    }
                    $pre  = collect($preData);
                    $post = collect($postData);
                    $plan = collect($planData);

                    $plan = $plan->map(function ($task) {
                        $task['task_type'] = 2;
                        return $task;
                    });
                    $plan = $plan->filter(function ($value, $key) {
                        return $value != 'null';
                    });
                    if (!$pre->isEmpty()) {
                        $pre = $pre->map(function ($pre) {
                            $pre['task_type'] = 1;
                            return $pre;
                        });
                        $pretasks = UtilityController::Createupdatearray($pre->toArray(), 'CustomSessionTasks');
                    }
                    if (!$post->isEmpty()) {
                        $post = $post->map(function ($task) {
                            $task['task_type'] = 3;
                            return $task;
                        });
                        $posttasks = UtilityController::Createupdatearray($post->toArray(), 'CustomSessionTasks');
                    }
                    $combinedDate = date('Y-m-d H:i:s', strtotime("$session->start_date $session->start_time"));
                    $sessionDate  = new Carbon($combinedDate);
                    $taskDuration = $sessionDate;
                    ## loop to assign time based on duration
                    $order = 1;
                    foreach ($plan->toArray() as $key => $value) {
                        $newDate           = new Carbon($combinedDate);
                        $value['ordering'] = $order;
                        if (isset($value['trigger_action']) && $value['trigger_action'] != null && $value['trigger_action'] != '' && $value['trigger_action'] != 'null') {
                            if ($value['trigger_action'] == 0) {
                                ## after task complete
                                $value['fire_at'] = $taskDuration;
                            } elseif ($value['trigger_action'] == 1) {
                                ## before session starts
                                $subMinute        = new Carbon($newDate->subMinute(5));
                                $value['fire_at'] = $subMinute;
                            } elseif ($value['trigger_action'] == 2) {
                                ## exact time
                            }
                        }
                        if (!isset($value['fire_at']) || $value['fire_at'] == null || $value['fire_at'] == 'null' || $value['fire_at'] == '') {
                            $value['fire_at'] = $taskDuration;
                        }
                        $taskId = $value['id'];
                        unset($value['id']);
                        $intasks = CustomSessionTasks::updateOrCreate(['id' => $taskId], $value);
                        if (isset($value['duration']) && $value['duration'] != null) {
                            $durations = explode(':', $value['duration']);
                            $taskDuration->addHour($durations['0']);
                            $taskDuration->addMinute($durations['1']);
                        }
                        $order++;
                    }
                    if ($intasks) {
                        $session->end_time = $taskDuration->toTimestring();
                        $session->save();
                        $returnData = UtilityController::Generateresponse(1, 'OOP_UPDATED', 200, '');
                        \DB::commit();
                    }
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomprocess
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get custom active/completed program info
    //In Params : void
    //Date : 3rd May 2018
    //###############################################################

    public function Getcustomprocess($processId, $type)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $processId  = base64_decode($processId);
            $type       = base64_decode($type);
            $program    = CustomProcess::alldata();
            if ($type == 2) {
                # active program
                $program = $program->active()->with('documents')->find($processId);
            } elseif ($type == 3) {
                # completed program
                $program = $program->completed()->with('documents')->find($processId);
            } else {
                return $returnData;
            }
            if ($program) {
                $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $program);
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Deletecustomdocument
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to delete custom program other documents
    //In Params : void
    //Date : 3rd May 2018
    //###############################################################

    public function Deletecustomdocument($documentId, $file, $client, $program)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $documentId = base64_decode($documentId);
            $file       = base64_decode($file);
            $client     = base64_decode($client);
            $program    = base64_decode($program);
            $document   = \App\CustomActivityDocuments::find($documentId);
            if ($document) {
                $deleteDocument = $this->Deletefile('other_documents', $file, $client, $program);
                if ($deleteDocument) {
                    $document->delete();
                    $returnData = UtilityController::Generateresponse(1, 'DOCUMENT_DELETED', 200, '');
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomsessioninfo
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get session info of custom program
    //In Params : void
    //Date : 3rd May 2018
    //###############################################################

    public function Getcustomsessioninfo($sessionId)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $sessionId  = base64_decode($sessionId);
            ## find session Data by it's id
            $responseData = CustomSessions::with(['tasks', 'batch.process.stakeholders.stakeholder' => function ($query) {
                $query->select('id', 'first_name', 'last_name');
            }])->find($sessionId);

            $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $responseData);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getcustomcalenderdata
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get custom calender data
    //In Params : void
    //Date : 3th May 2018
    //###############################################################

    public function Getcustomcalenderdata($id)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $id         = base64_decode($id);
            $process    = CustomProcess::with('tasks')->find($id)->toArray();
            $collection = collect($process['tasks']);
            $added      = $collection->map(function ($data) {
                $time          = $data['fire_at'] && $data['fire_at'] != null ? $data['fire_at'] : $data['due_date'];
                $time          = Carbon::parse($time)->toDateTimestring();
                $data['start'] = $time;
                $data['color'] = $data['status'] == 1 ? 'green' : '#bf2026';
                $data['title'] = $data['title'] && $data['title'] != null ? $data['title'] : $data['name'];
                return $data;
            })->sortBy('start')->values();
            $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $added);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Deletecustomprocess
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to delete custom process
    //In Params : void
    //Date : 11th May 2018
    //###############################################################

    public function Deletecustomprocess($processId)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $processId  = base64_decode($processId);
            $process    = CustomProcess::find($processId);
            if ($process) {
                if ($process->delete()) {
                    $returnData = UtilityController::Generateresponse(1, 'PROGRAM_DELETED', 200, '');
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Removecustomparticipant
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to delete custom participant
    //In Params : void
    //Date : 7th June 2018
    //###############################################################

    public function Removecustomparticipant($participantId)
    {
        try {
            $returnData    = UtilityController::Setreturnvariables();
            $participantId = base64_decode($participantId);
            if ($participantId) {
                $participant = \App\CustomParticipants::find($participantId);
                if ($participant && $participant->delete()) {
                    $returnData = UtilityController::Generateresponse(1, 'PARTICIPANT_REMOVED', '', '');
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Custombatchdateupdate
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to update batch date dcustom
    //In Params : void
    //Date : 23th July 2018
    //###############################################################

    public function Custombatchdateupdate(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            if ($request) {
                $batch = \App\CustomBatches::find($request->id);
                if ($batch) {
                    $batch->starts_on = $request->starts_on;
                    if ($batch->save()) {
                        $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', '', '');
                    }
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }
## ------------------------------------------------------- private operations -----------------------------------------------------##

    //###############################################################
    //Function Name : AddUpdateStakeholders
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : add update stakeholder of custom program
    //In Params : void
    //Date : 1th May 2018
    //###############################################################

    public function AddUpdateStakeholders(array $holderData)
    {
        try {
            ## default validation assigned
            $valid = true;
            ## validate request
            $returnData = UtilityController::ValidationRules($holderData, 'CustomProgramsStakeholders');
            if ($returnData['status']) {
                ## loop through holderlist
                foreach ($holderData as $key => $holder) {
                    if (!$holder['name'] && !$holder['designation_id']) {
                        return UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, '');
                    }
                    ## inside loop of stakeholderdata
                    foreach ($holder['holderlist'] as $key => $holdervalue) {
                        if (!$holdervalue['stackholder_id']) {
                            return UtilityController::Generateresponse(0, 'NO_STAKEHOLDER', 200, '');
                        }
                        ## if data don't have any id it means it's a fresh insert.
                        if (!isset($holdervalue['id'])) {
                            ## create new program stakeholders
                            $newProgramHolder = CustomProgramsStakeholders::create($holdervalue);
                            if ($newProgramHolder) {
                                ## create process stakeholders
                                $newProcessHolders = $newProgramHolder->processholders()->create($holdervalue);
                                if (!$newProcessHolders) {
                                    $valid = false;
                                }
                            } else {
                                $valid = false;
                            }

                        } else {
                            ## if data have id means it's an update call. update process stakeholders
                            $update = UtilityController::Makemodelobject($holdervalue, 'CustomStakeholders', 'id', $holdervalue['id']);
                            if (!$update) {
                                $valid = false;
                            }
                        }
                    }
                }
                if ($valid) {
                    ## if above all steps works correctly modufy return data
                    $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, '');
                }
            }
            ## return response
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Getmodulelist
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to get lists of modules from ids
    //In Params : void
    //Date : 18th April 2018
    //###############################################################

    private function Getmodulelist(array $modules)
    {
        ## create new collection object
        $collection = new Collection();
        ## validate if collection is an array
        if (is_array($modules)) {
            ## loop to find data
            foreach ($modules as $mkey => $mvalue) {
                ## find program. if program is assigned, then only allow to move
                $program = Programs::find($mvalue['program_id']);
                if ($program) {
                    ## check if module is 0 means all modules of that program
                    if ($mvalue['module'] == 0) {
                        ## get modules of that program
                        $data = $program->module;
                        ## push those modules into collection object
                        $data->map(function ($datas) use ($collection, $program) {
                            $datas['module_id']    = $datas['id'];
                            $datas['c_program_id'] = $program->id;
                            $collection->push($datas);
                        });
                    } else {
                        ## if module is not 0 then it must be an id. find module
                        ## and push it into collection object
                        $data                 = PlanSessionModules::find($mvalue['module'])->toArray();
                        $data['module_id']    = $data['id'];
                        $data['c_program_id'] = $program->id;
                        $collection->push($data);
                    }
                }
            }
            ## if collection data available return after converting into an array
            if ($collection) {
                return $collection->toArray();
            } else {
                ## else return empty array
                return [];
            }
        } else {
            ## return empty array
            return [];
        }
    }

    //###############################################################
    //Function Name : Createlink
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to create a link to add new participants
    //In Params : void
    //Date : 30th April 2018
    //###############################################################

    private function Createlink($clientId, $processId)
    {
        try {
            ## default return data
            $returnData = UtilityController::Setreturnvariables();
            ## create data
            $link               = [];
            $link['client_id']  = base64_decode($clientId);
            $link['process_id'] = base64_decode($processId);
            $link['link']       = url("customparticipants/$clientId/$processId");
            $link['expires_at'] = Carbon::now()->addDays(3)->toDateTimeString();
            $newlink            = CustomLink::create($link);
            if ($newlink) {
                $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', 200, $newlink);
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Uploadfiles
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to upload all types of files
    //In Params : Void
    //Return : json
    //Date : 18th April 2018
    //###############################################################
    private function Uploadfiles($type, $file, $clientId, $programId)
    {
        try {
            if (is_array($file)) {
                $storageName = [];
                foreach ($file as $key => $value) {
                    $storageName[]['name'] = $this->Uploadfiles($type, $value, $clientId, $programId);
                }
            } else {
                $directory = "custom/$clientId/$programId/$type";
                $fileName  = $file->getClientOriginalName();
                $name      = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $extention = $file->guessClientExtension();
                $append    = 2;
                ## if same name file already available
                while (Storage::exists("$directory/$fileName")) {
                    $fileName = $name . $append . '.' . $extention;
                    $append++;
                }
                ## save file
                $storagePath = Storage::putFileAs($directory, $file, $fileName, 'public');
                ## get file base name
                $storageName = basename($storagePath);
            }
            ## return file name
            return $storageName;

        } catch (\Exception $e) {
            ## exception mail
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            ## return response
            return response()->json(UtilityController::Setreturnvariables());
        }
    }

    //###############################################################
    //Function Name : Deletefile
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to upload all types of files
    //In Params : Void
    //Return : json
    //Date : 18th April 2018
    //###############################################################

    private function Deletefile($type, $file, $clientId, $programId)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $directory  = "$clientId/$programId/$type";
            if (is_array($file)) {
                $deleted = 1;
                foreach ($file as $key => $value) {
                    $delete = $this->Deletefile($type, $value, $clientId, $programId);
                    if (!$delete) {$deleted = 0;}
                }
                return $deleted;
            } else {
                $deleted = 1;
                $delete  = Storage::disk('custom')->delete("$directory/$file");
                if (!$delete && Storage::disk('custom')->has("$directory/$file")) {$deleted = 0;}
                return $deleted;
            }
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return response()->json(UtilityController::Setreturnvariables());
        }

    }

    //###############################################################
    //Function Name : Findfileandsave
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : find file from array and save, get file name
    //In Params : void
    //Date : 2nd May 2018
    //###############################################################
    private function Findfileandsave(array $data)
    {
        foreach ($data as $key => $value) {
            if (isset($value['other']) && is_array($value['other'])) {
                foreach ($value['other'] as $okey => $ovalue) {
                    if (isset($ovalue['file'])) {
                        if (!is_array($ovalue['text'])) {
                            $ovalue['text'] = [];
                        }
                        $fileList                      = $this->Uploadtasksfiles($ovalue['file']);
                        $value['other'][$okey]['text'] = array_merge($ovalue['text'], $fileList);
                        unset($value['other'][$okey]['file']);
                    }
                }
            }
            if (isset($value['other_files']) && !empty($value['other_files'])) {
                if (empty($value['other']) || !is_array($value['other'])) {
                    $value['other'] = [];
                }
                $fileList = $this->Uploadtasksfiles($value['other_files']);
                unset($value['other_files']);
                $data[$key]['other'] = $value['other'] = array_merge($value['other'], $fileList);
            }
            $collection = collect($value);
            $data[$key] = $collection->filter(function ($pvalue, $key) {
                return $pvalue != 'null';
            })->toArray();
        }
        return $data;
    }

    //###############################################################
    //Function Name : Uploadtasksfiles
    //Author : Bhargav Bhanderi <bhargav@creolestudios.com>
    //Purpose : to upload files attached in tasks
    //In Params : void
    //Date : 2nd May 2018
    //###############################################################

    private function Uploadtasksfiles(array $files)
    {
        $fileList = [];
        foreach ($files as $filekey => $file) {
            $directory = "tasksfile";
            $fileName  = $file->getClientOriginalName();
            $name      = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $extention = $file->guessClientExtension();
            $append    = 2;
            ## if same name file already available
            while (Storage::exists("$directory/$fileName")) {
                $fileName = $name . $append . '.' . $extention;
                $append++;
            }
            ## save file
            $storagePath = Storage::putFileAs($directory, $file, $fileName, 'public');
            ## get file base name
            $fileList[] = basename($storagePath);
        }
        return $fileList;
    }

    private function Informstakeholder(array $taskvalue, $batchId)
    {
        try {
            # Custom
            if (isset($taskvalue['i_stackholder_id']) && $taskvalue['i_stackholder_id'] != 'null' && $taskvalue['i_stackholder_id'] != null) {
                $stakeholder = \App\CustomStakeholders::find($taskvalue['i_stackholder_id'])->stakeholder;
                if ($stakeholder) {
                    $program                   = \App\CustomBatches::find($batchId)->process->program->toArray();
                    $programName               = $program['title'] . "(Custom)";
                    $stakeholder               = $stakeholder->toArray();
                    $newRecord                 = array_merge($stakeholder, $taskvalue);
                    $newRecord['program_name'] = $programName;
                    Mail::to($newRecord['email'])->later(Carbon::now(), new TaskAssignedMail($newRecord));

                    $notification                  = new SystemNotifications;
                    $notification->notification_to = $stakeholder['id'];
                    $notification->message         = 'You are assigned to ' . $taskvalue['name'] . ' of ' . $programName . ' program';
                    $notification->save();

                } else {
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return false;
        }
    }
}
