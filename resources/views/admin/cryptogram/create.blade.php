@extends('brackets/admin-ui::admin.layout.default')

@section('title', trans('admin.cryptogram.actions.create'))

@section('body')

    <div class="container-xl">

        <div class="card">

            <cryptogram-form :action="'{{ url('admin/cryptograms') }}'" :tags="{{ $tags->toJSON() }}" v-cloak
                inline-template>

                <form class="form-horizontal form-create" method="post" @submit.prevent="onSubmit" :action="action"
                    novalidate>

                    <div class="card-header">
                        <i class="fa fa-plus"></i> {{ trans('admin.cryptogram.actions.create') }}
                    </div>

                    <div class="card-body">
                        @include('admin.cryptogram.components.form-elements')
                        @include('brackets/admin-ui::admin.includes.media-uploader', [
                        'mediaCollection' => app(App\Models\Cryptogram::class)->getMediaCollection('picture'),
                        'label' => 'Thumbnail'
                        ])
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary" :disabled="submiting">
                            <i class="fa" :class="submiting ? 'fa-spinner' : 'fa-download'"></i>
                            {{ trans('brackets/admin-ui::admin.btn.save') }}
                        </button>
                    </div>

                </form>

            </cryptogram-form>

        </div>

    </div>


@endsection
