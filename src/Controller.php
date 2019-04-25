<?php namespace ShuvroRoy\TranslationManager;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use ShuvroRoy\TranslationManager\Models\Translation;
use Illuminate\Support\Collection;
use \Illuminate\Pagination\LengthAwarePaginator;
use Tanmuhittin\LaravelGoogleTranslate\Commands\TranslateFilesCommand;

class Controller extends BaseController
{
    /** @var \ShuvroRoy\TranslationManager\Manager  */
    protected $manager;

    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    public function getIndex($group = null)
    {
        $locales = $this->manager->getLocales();
        $groups = Translation::groupBy('group');
        $excludedGroups = $this->manager->getConfig('exclude_groups');
        if($excludedGroups){
            $groups->whereNotIn('group', $excludedGroups);
        }

        $groups = $groups->select('group')->orderBy('group')->get()->pluck('group', 'group');
        if ($groups instanceof Collection) {
            $groups = $groups->all();
        }
        $groups = [''=>'Choose a group'] + $groups;
        $numChanged = Translation::where('group', $group)->where('status', Translation::STATUS_CHANGED)->count();

        $val = null;
        // Get order
        if(request()->has('order')) {
            $order = request('order');
        } else {
            $order = 'asc';
        }

        if(request()->get('order') == 'desc') {
            $val = true;
        }

        $orderBy = request('orderBy') ?: '';
        if(request()->has('orderBy')) {
            $orderBy = request('orderBy');
            $orderByArray = explode('-', $orderBy);
            $column = $orderByArray[0];
            $direction = trim($orderByArray[1]);

            $allTranslations = Translation::where('group', $group)->orderBy('value', $direction);
        } else {
            $allTranslations = Translation::where('group', $group)->orderBy('key', $order);
        }
        
        $numTranslations = $allTranslations->count();
        $translations = [];
        $verified = request()->get('page') == 'all';
        $searchContent = [];
        
        if(request()->has('search')) {
            $searchTerm = request()->get('search');
            $keys = Translation::where('group', $group)->orderBy('key', 'asc')
                           ->where('key', 'LIKE', "%{$searchTerm}%") 
                           ->orWhere('value', 'LIKE', "%{$searchTerm}%")
                           ->get(['key']);
           
            foreach ($keys as $key) {
                foreach ($allTranslations->get() as $translation) {
                    if($translation['key'] == $key['key']) {
                        $searchContent[] = $translation;
                    }
                }
            }
        }

        if(request()->has('search')) {
            $allTranslations = $searchContent;
        } else {
            if(request()->has('orderBy')) {
                $allTranslations = $allTranslations->get();
            } else {
                $allTranslations = $allTranslations->get()->sortBy('key', SORT_NATURAL|SORT_FLAG_CASE, $val);
            }
           
        }

        foreach($allTranslations as $translation){
            $translations[$translation->key][$translation->locale] = $translation;
        }

        foreach($locales as $locale) {
            $empty[$locale] = [];

            foreach($translations as $key => $t) {
                if(!array_key_exists($locale, $t) || $t[$locale]->value == '') {
                    $empty[$locale][$key] = $t;
                }
            }
        }

        $unapproved = [];
        foreach ($translations as $key => $translation) {
            foreach ($translation as $value) {
                if ($value['status'] == 1) {
                    $unapproved[$key] = $translation;
                    break; 
                }
            }
        }

        if ($this->manager->getConfig('pagination_enabled') && !$verified) {
            // For all translations
            $total = count($translations);
            $page = (request()->has('page') && empty(request()->all())) || request()->has('all') ? request()->get('page') : 1;
            $per_page = $this->manager->getConfig('per_page');
            $offSet = ($page * $per_page) - $per_page;  
            $itemsForCurrentPage = array_slice($translations, $offSet, $per_page, true);
            $prefix = $this->manager->getConfig('route')['prefix'];
            $path = url("$prefix/view/$group?all=true");

            $paginator = new LengthAwarePaginator($itemsForCurrentPage, $total, $per_page, $page);
            $translations = $paginator->withPath($path);

            // For unapproved translations
            $totalUnapproved = count($unapproved);
            $pageUnapproved = request()->has('page') && request()->has('status') ? request()->get('page') : 1;
            $per_page = $this->manager->getConfig('per_page');
            $offSetUnapproved = ($pageUnapproved * $per_page) - $per_page;  
            $itemsForCurrentPageUnapproved = array_slice($unapproved, $offSetUnapproved, $per_page, true);
            $prefix = $this->manager->getConfig('route')['prefix'];
            $pathUnapproved = url("$prefix/view/$group?status=unapproved");

            $paginatorUnapproved = new LengthAwarePaginator($itemsForCurrentPageUnapproved, $totalUnapproved, $per_page, $pageUnapproved);
            $unapproved = $paginatorUnapproved->withPath($pathUnapproved);

            // For Empty local
            foreach($locales as $locale) {
                ${'totalEmpty'.$locale} = count($empty[$locale]);
                ${'pageEmpty'.$locale} = request()->has('page') && request()->has($locale) ? request()->get('page') : 1;
                $per_page = $this->manager->getConfig('per_page');
                ${'offSet'.$locale} = (${'pageEmpty'.$locale} * $per_page) - $per_page;  
                ${'itemsForCurrentPageEmpty'.$locale} = array_slice($empty[$locale], ${'offSet'.$locale}, $per_page, true);
                $prefix = $this->manager->getConfig('route')['prefix'];
                ${'path'.$locale} = url("$prefix/view/$group?$locale=empty");

                ${'paginator'.$locale} = new LengthAwarePaginator(${'itemsForCurrentPageEmpty'.$locale}, ${'totalEmpty'.$locale}, $per_page, ${'pageEmpty'.$locale});
                $empty[$locale] = ${'paginator'.$locale}->withPath(${'path'.$locale});
            }
        }

        return view('translation-manager::index')
            ->with('translations', $translations)
            ->with('locales', $locales)
            ->with('groups', $groups)
            ->with('group', $group)
            ->with('numTranslations', $numTranslations)
            ->with('numChanged', $numChanged)
            ->with('editUrl', action('\ShuvroRoy\TranslationManager\Controller@postEdit', [$group]))
            ->with('deleteEnabled', $this->manager->getConfig('delete_enabled'))
            ->with('paginationEnabled', $this->manager->getConfig('pagination_enabled') && !$verified && ! request()->has('search'))
            ->with('order', $order)
            ->with('orderBy', $orderBy)
            ->with('emptyLocales', $empty)
            ->with('unapproved', $unapproved);
    }

    public function getView($group = null)
    {
        return $this->getIndex($group);
    }

    protected function loadLocales()
    {
        //Set the default locale as the first one.
        $locales = Translation::groupBy('locale')
            ->select('locale')
            ->get()
            ->pluck('locale');

        if ($locales instanceof Collection) {
            $locales = $locales->all();
        }
        $locales = array_merge([config('app.locale')], $locales);
        return array_unique($locales);
    }

    public function postAdd($group = null)
    {
        $keys = explode("\n", request()->get('keys'));

        foreach($keys as $key){
            $key = trim($key);
            if($group && $key){
                $this->manager->missingKey('*', $group, $key);
            }
        }
        return redirect()->back();
    }

    public function postEdit($group = null)
    {
        if(!in_array($group, $this->manager->getConfig('exclude_groups'))) {
            $name = request()->get('name');
            $value = request()->get('value');

            list($locale, $key) = explode('|', $name, 2);
            $translation = Translation::firstOrNew([
                'locale' => $locale,
                'group' => $group,
                'key' => $key,
            ]);
            $translation->value = (string) $value ?: null;
            $translation->status = Translation::STATUS_CHANGED;
            $translation->save();
            return array('status' => 'ok');
        }
    }

    public function postDelete($group = null, $key)
    {
        if(!in_array($group, $this->manager->getConfig('exclude_groups')) && $this->manager->getConfig('delete_enabled')) {
            Translation::where('group', $group)->where('key', $key)->delete();
            return ['status' => 'ok'];
        }
    }

    public function postApprove($group = null)
    {
        if(!in_array($group, $this->manager->getConfig('exclude_groups'))) {   
            $path = resource_path() . '/lang/' . request('locale') . '.json';
            $key = request('key');
            $data = [$key => request('content')];
            if(!file_exists($path)) {
                file_put_contents($path, '{}');
            }
            $strJsonFileContents = file_get_contents($path);
            $translations = json_decode($strJsonFileContents, true);
            if (in_array($key, $translations)) {
                $translations[$key] = request('content');
            } else {
                $translations = array_merge($translations, $data);
            }
           
            $output = json_encode( $translations, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE );
            
            $this->manager->files()->put( $path, $output );
            Translation::where('group', $group)->where('id', request('id'))->update([ 'status' => Translation::STATUS_SAVED ]);
        }

        return redirect(request('previous'));
    }

    public function postImport(Request $request)
    {
        $replace = $request->get('replace', false);
        $counter = $this->manager->importTranslations($replace);

        return ['status' => 'ok', 'counter' => $counter];
    }

    public function postFind()
    {
        $numFound = $this->manager->findTranslations();

        return ['status' => 'ok', 'counter' => (int) $numFound];
    }

    public function postPublish($group = null)
    {
         $json = false;

        if($group === '_json'){
            $json = true;
        }

        $this->manager->exportTranslations($group, $json);

        return ['status' => 'ok'];
    }

    public function postAddGroup(Request $request)
    {
        $group = str_replace(".", '', $request->input('new-group'));
        if ($group)
        {
            return redirect()->action('\ShuvroRoy\TranslationManager\Controller@getView',$group);
        }
        else
        {
            return redirect()->back();
        }
    }

    public function postAddLocale(Request $request)
    {
        $locales = $this->manager->getLocales();
        $newLocale = str_replace([], '-', trim($request->input('new-locale')));
        if (!$newLocale || in_array($newLocale, $locales)) {
            return redirect()->back();
        }
        $this->manager->addLocale($newLocale);
        return redirect()->back();
    }

    public function postRemoveLocale(Request $request)
    {
        foreach ($request->input('remove-locale', []) as $locale => $val) {
            $this->manager->removeLocale($locale);
        }
        return redirect()->back();
    }

    public function postTranslateMissing(Request $request){
        $locales = $this->manager->getLocales();
        $newLocale = str_replace([], '-', trim($request->input('new-locale')));
        if($request->has('with-translations') && $request->has('base-locale') && in_array($request->input('base-locale'),$locales) && $request->has('file') && in_array($newLocale, $locales)){
            $base_locale = $request->get('base-locale');
            $group = $request->get('file');
            $base_strings = Translation::where('group', $group)->where('locale', $base_locale)->get();
            foreach ($base_strings as $base_string) {
                $base_query = Translation::where('group', $group)->where('locale', $newLocale)->where('key', $base_string->key);
                if ($base_query->exists() && $base_query->whereNotNull('value')->exists()) {
                    // Translation already exists. Skip
                    continue;
                }
                $translated_text = TranslateFilesCommand::translate($base_locale, $newLocale, $base_string->value);
                request()->replace([
                    'value' => $translated_text,
                    'name' => $newLocale . '|' . $base_string->key,
                ]);
                app()->call(
                    'ShuvroRoy\TranslationManager\Controller@postEdit',
                    [
                        'group' => $group
                    ]
                );
            }
            return redirect()->back();
        }
        return redirect()->back();
    }
}
