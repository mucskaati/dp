<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cryptogram\BulkDestroyCryptogram;
use App\Http\Requests\Admin\Cryptogram\DestroyCryptogram;
use App\Http\Requests\Admin\Cryptogram\IndexCryptogram;
use App\Http\Requests\Admin\Cryptogram\StoreCryptogram;
use App\Http\Requests\Admin\Cryptogram\UpdateCryptogram;
use App\Http\Requests\Admin\General\UpdateState;
use App\Mail\UpdateCryptogramMail;
use App\Mail\UpdateCryptogramStateMail;
use App\Models\Category;
use App\Models\Cryptogram;
use App\Models\Data;
use App\Models\Datagroup;
use App\Models\Language;
use App\Models\Location;
use App\Models\Person;
use App\Models\Solution;
use App\Models\State;
use App\Models\Tag;
use Brackets\AdminListing\Facades\AdminListing;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class CryptogramsController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @param IndexCryptogram $request
     * @return array|Factory|View
     */
    public function index(IndexCryptogram $request)
    {
        // create and AdminListing instance for a specific model and
        $data = AdminListing::create(Cryptogram::class)->processRequestAndGet(
            // pass the request with params
            $request,

            // set columns to query
            ['availability', 'category_id', 'day', 'flag', 'id', 'image_url', 'language_id', 'location_id', 'month', 'name', 'recipient_id', 'sender_id', 'solution_id', 'state_id', 'year'],

            // set columns to searchIn
            ['availability', 'description', 'id', 'image_url', 'name']
        );

        if ($request->ajax()) {
            if ($request->has('bulk')) {
                return [
                    'bulkItems' => $data->pluck('id')
                ];
            }
            return ['data' => $data];
        }

        return view('admin.cryptogram.index', ['data' => $data]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @throws AuthorizationException
     * @return Factory|View
     */
    public function create()
    {
        $this->authorize('admin.cryptogram.create');

        $locations = Location::all();
        $languages = Language::all();
        $persons = Person::all();
        $tags = Tag::where('type', Tag::CRYPTOGRAM)
            ->orWhereNull('type')
            ->get();
        $solutions = Solution::all();
        $categories = Category::orderBy('name', 'asc')->get();
        $groups = Datagroup::all();

        return view('admin.cryptogram.create', compact(
            'locations',
            'languages',
            'persons',
            'tags',
            'solutions',
            'categories',
            'groups'
        ));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreCryptogram $request
     * @return array|RedirectResponse|Redirector
     */
    public function store(StoreCryptogram $request)
    {
        // Sanitize input
        $sanitized = $request->getSanitized();


        // Store the Cryptogram
        $cryptogram = Cryptogram::create($sanitized);

        //Sync datagroups
        $this->syncDatagroups($cryptogram, $sanitized);

        //Sync tags
        $this->syncTags($cryptogram, $sanitized);

        if ($request->ajax()) {
            return ['redirect' => url('admin/cryptograms'), 'message' => trans('brackets/admin-ui::admin.operation.succeeded')];
        }

        return redirect('admin/cryptograms');
    }

    /**
     * Display the specified resource.
     *
     * @param Cryptogram $cryptogram
     * @throws AuthorizationException
     * @return void
     */
    public function show(Cryptogram $cryptogram)
    {
        $this->authorize('admin.cryptogram.show', $cryptogram);

        // TODO your code goes here
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param Cryptogram $Cryptogram
     * @throws AuthorizationException
     * @return Factory|View
     */
    public function edit(Cryptogram $cryptogram)
    {
        $this->authorize('admin.cryptogram.edit', $cryptogram);

        $locations = Location::all();
        $languages = Language::all();
        $persons = Person::all();
        $tags = Tag::where('type', Tag::CRYPTOGRAM)
            ->orWhereNull('type')
            ->get();
        $solutions = Solution::all();
        $categories = Category::orderBy('name', 'asc')->get();
        $states = collect(State::STATUSES)->toJSON();

        $cryptogram->load(['category', 'recipient', 'sender', 'location', 'tags', 'solution', 'language', 'groups', 'groups.data']);


        return view('admin.cryptogram.edit', [
            'cryptogram' => $cryptogram,
            'locations' => $locations,
            'languages' => $languages,
            'persons' => $persons,
            'tags' => $tags,
            'solutions' => $solutions,
            'categories' => $categories,
            'states' => $states
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateCryptogram $request
     * @param Cryptogram $Cryptogram
     * @return array|RedirectResponse|Redirector
     */
    public function update(UpdateCryptogram $request, Cryptogram $cryptogram)
    {
        // Sanitize input

        $sanitized = $request->getSanitized();

        //dd($sanitized);

        // Update changed values Cryptogram
        $cryptogram->update($sanitized);

        //Sync datagroups
        $this->syncDatagroups($cryptogram, $sanitized);

        //Sync tags
        $this->syncTags($cryptogram, $sanitized);

        if ($request->ajax()) {
            return [
                'redirect' => url('admin/cryptograms'),
                'message' => trans('brackets/admin-ui::admin.operation.succeeded'),
            ];
        }

        return redirect('admin/cryptograms');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param DestroyCryptogram $request
     * @param Cryptogram $Cryptogram
     * @throws Exception
     * @return ResponseFactory|RedirectResponse|Response
     */
    public function destroy(DestroyCryptogram $request, Cryptogram $cryptogram)
    {
        $cryptogram->delete();

        if ($request->ajax()) {
            return response(['message' => trans('brackets/admin-ui::admin.operation.succeeded')]);
        }

        return redirect()->back();
    }

    /**
     * Remove the specified resources from storage.
     *
     * @param BulkDestroyCryptogram $request
     * @throws Exception
     * @return Response|bool
     */
    public function bulkDestroy(BulkDestroyCryptogram $request): Response
    {
        DB::transaction(static function () use ($request) {
            collect($request->data['ids'])
                ->chunk(1000)
                ->each(static function ($bulkChunk) {
                    Cryptogram::whereIn('id', $bulkChunk)->delete();

                    // TODO your code goes here
                });
        });

        return response(['message' => trans('brackets/admin-ui::admin.operation.succeeded')]);
    }

    /**
     * Sync datagroups
     *
     * @param Cryptogram $cryptogram
     * @param array $sanitized
     * @return void
     */
    private function syncDatagroups(Cryptogram $cryptogram, $sanitized)
    {

        // 1. Delete groups
        $cryptogram->groups()->delete();

        // 2. Sync new groups
        foreach ($sanitized['groups'] as $keyGroup => $group) {
            $newGroup = Datagroup::create(['description' => $group->description, 'cryptogram_id' => $cryptogram->id]);
            $data = collect($group->data)->toArray();
            foreach ($data as $keyData => $item) {

                $item = collect($item)->toArray();
                $type = collect($item['type'])->toArray();

                $dataBlobb = $item['link'] ?: $item['text'];
                $newData = Data::create([
                    'blobb' => $type['id'] == 'image' ? 'image' : $dataBlobb,
                    'description' => $item['title'],
                    'filetype' => $type['id'],
                    'datagroup_id' => $newGroup->id,
                    'dl_protection' => 0
                ]);

                //Sync image to data in datagroup
                if (isset($sanitized['images'][$keyGroup][$keyData]) && $sanitized['images'][$keyGroup][$keyData] !== 'undefined') {

                    $newData
                        ->addMedia($sanitized['images'][$keyGroup][$keyData])
                        ->toMediaCollection('image');

                    $newData->update(['blobb' => $newData->getFirstMediaPath('image')]);
                } elseif (isset($item['image']) && $item['image']) {
                    $newData
                        ->addMediaFromUrl($item['image'])
                        ->toMediaCollection('image');
                }
            }
        }

        // // 3. Sync predefined groups
        // $predefinedGroups = collect($sanitized['predefined_groups'])->pluck('id')->toArray();
        // $cryptogram->datagroups()->sync($predefinedGroups);
    }

    /**
     * Sync tags
     *
     * @param Cryptogram $key
     * @param array $sanitized
     * @return void
     */
    public function syncTags(Cryptogram $cryptogram, $sanitized)
    {
        $tags = collect($sanitized['tags'])->pluck('name')->toArray();
        $tags = Tag::whereIn('name', $tags)->where('type', Tag::CRYPTOGRAM)->get();
        $cryptogram->tags()->sync($tags->pluck('id')->toArray());
    }

    /**
     * Update cryptograms state
     *
     * @param UpdateStateCipherKey $request
     * @param Cryptogram $cryptogram
     * @return void
     */
    public function changeState(UpdateState $request, Cryptogram $cryptogram)
    {
        $sanitized = $request->getSanitized();

        $state = State::create([
            'name' => $cryptogram->name,
            'state' => $sanitized['state'],
            'note' => $sanitized['note'],
            'created_by' => auth()->user()->id
        ]);

        $cryptogram->update(['state_id' => $state->id]);

        Mail::to($cryptogram->submitter->email)->send(new UpdateCryptogramStateMail($cryptogram));

        return response()->json('Successfully status changed.', 200);
    }
}
