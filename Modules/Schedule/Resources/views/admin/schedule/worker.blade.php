@extends('layouts.master')

@section('content-header')
<h1>
    Leave Application
</h1>
<ol class="breadcrumb">
    <li><a href="{{ URL::route('dashboard.index') }}"><i class="fa fa-dashboard"></i> {{ trans('core::core.breadcrumb.home') }}</a></li>
    <li class="active">Leave application</li>
</ol>
@stop
@push('css-stack')
<!-- Latest compiled and minified CSS -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
<link rel="stylesheet" href="{{ Module::asset('schedule:css/bootstrap-datepicker.min.css') }}">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/vis/4.20.1/vis.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/css/select2.min.css" rel="stylesheet" />

@endpush


@section('content')
<div class="row">
    <div class="col-xs-12">

        <div class="box box-primary">
            <div class="box-header">
            </div>
            <!-- /.box-header -->
            <div class="box-body">
                <div class="row">
                    <div class="col-xs-12" id="step1">
                        <div class="panel panel-default">
                            <div class="panel-heading">Step 1: Select absent date</div>
                            <div class="panel-body">
                                <div class="well">
                                    <div class="row">
                                        <div class="col-xs-12">
                                            <div id="datepicker"></div>
                                            <input type="hidden" id="my_hidden_input" />
                                            <input type="hidden" id="teacherId" value="{{$teacher->id}}" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xs-12" id="step2">
                        <div class="panel panel-default">
                            <div class="panel-heading">Step 2: Select subject name</div>
                            <div class="panel-body">
                                <div class="row">
                                    <div class="col-xs-12">
                                        <div id="visualization"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xs-12" id="step3">
                        <div class="panel panel-default">
                            <div class="panel-heading">Step 3: Select Full Day absent or Partial Day absent </div>
                            <div class="panel-body">
                                <div class="row btn-pref">
                                    <div class="col-xs-4">
                                        <button type="button" class="btn btn-primary" id="fullDay" href="#tab1" data-toggle="tab">FULL DAY Absent</button>
                                    </div>
                                    <div class="col-xs-4">
                                        <button type="button" class="btn btn-default" id="partialDay" href="#tab2" data-toggle="tab">PARTIAL DAY Absent</button>
                                    </div>
                                    <div class="col-xs-4">
                                        <button type="button" class="btn btn-default" id="prolonged" href="#tab3" data-toggle="tab">PROLONGED Absent</button>
                                    </div>
                                </div>
                                <div class="well">
                                    <div class="tab-content">
                                        <div class="tab-pane fade in active" id="tab1">

                                        </div>
                                        <div class="tab-pane fade in" id="tab2">
                                            <div class="row">
                                                <div class="col-xs-4"></div>
                                                <div class="col-xs-4">
                                                    <label>Absent From</label>
                                                    <div class="form-group">
                                                        <select class="form-control">
                                                            <option>7:00 AM</option>
                                                            <option>7:30 AM</option>
                                                        </select>
                                                    </div>
                                                    <label>Absent To</label>
                                                    <div class="form-group">
                                                        <select class="form-control">
                                                            <option>7:00 AM</option>
                                                            <option>7:30 AM</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-xs-4"></div>
                                            </div>
                                        </div>
                                        <div class="tab-pane fade in" id="tab3">
                                            <div class="row">
                                                <div class="col-xs-4"></div>
                                                <div class="col-xs-4">
                                                    <label> From</label>
                                                    <div class="form-group">
                                                        <input type="text" value="" placeholder="{{\Carbon\Carbon::today()->format('d-m-Y')}}" />
                                                    </div>
                                                    <label> To</label>
                                                    <div class="form-group">
                                                        <input type="text" value="" placeholder="{{\Carbon\Carbon::today()->format('d-m-Y')}}" />
                                                    </div>
                                                </div>
                                                <div class="col-xs-4"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xs-12" id="step4">
                        <div class="panel panel-default">
                            <div class="panel-heading">Step 4: State the reason and confirm</div>
                            <div class="panel-body">
                                <div class="row">
                                    <div class="col-xs-12">
                                        <form class="form-horizontal" method="post" action="{{route('admin.schedule.sendSMS')}}">
                                            {{csrf_field()}}
                                            <div class="teacherForm">
                                                <div class="form-group">
                                                    <label for="name" class="col-md-4 control-label">Select Teacher</label>
                                                    <div class="col-md-5">
                                                        <select class="form-control" id="selectedUserAvailabel">
                                                            @if(count($teachers) > 0)
                                                                @foreach($teachers as $item)
                                                                    <option value="{{$item->id}}">{{$item->name}}</option>
                                                                @endforeach
                                                            @endif
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <a id="addMoreTeacher" href="javascript:void(0)"><span class="glyphicon glyphicon-plus" aria-hidden="true"></span>Add more teacher</a>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label for="name" class="col-md-4 control-label">Reason for being absent</label>
                                                <div class="col-md-6">
                                                    <select class="form-control">
                                                        <option>Choose reason bellow</option>
                                                        <option>Official Reasons</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="name" class="col-md-4 control-label">Addition Information(optional):</label>
                                                <div class="col-md-6">
                                            <textarea class="form-control" cols="4" rows="5"></textarea>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <div class="col-md-6 col-md-offset-4">
                                                    <button type="submit" class="btn btn-primary">
                                                        Confirm
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <!-- /.box-body -->
            </div>
        <!-- /.box -->
    </div>
<!-- /.col (MAIN) -->
</div>
</div>


@stop

@push('js-stack')
<?php $locale = App::getLocale(); ?>
<!-- Include all compiled plugins (below), or include individual files as needed -->
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<script src="{{ Module::asset('schedule:js/bootstrap-datepicker.min.js') }}"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/vis/4.20.1/vis.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/js/select2.min.js"></script>

<script src="{{ Module::asset('schedule:js/worker.js?v='.\Carbon\Carbon::now()->timestamp) }}" type="text/javascript"></script>
@endpush