<?php
/**
 * File UserController.php
 */ 

namespace App\Http\Controllers;

use App\Http\Resources\PermissionResource;
use App\Http\Resources\UserResource;
use App\Lms\Models\Permission;
use App\Lms\Models\Role;
use App\Lms\Models\User;
use App\Lms\Models\PermissionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Validator;
use Image;
use File;

/**
 * Class UserController
 *
 * @package App\Http\Controllers
 */
class UserController extends Controller
{
    const ITEM_PER_PAGE = 15;

    /**
     * Display a listing of the user resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response|ResourceCollection
     */
    public function index(Request $request)
    {
        $searchParams = $request->all();
        $userQuery = User::query();
        $limit = Arr::get($searchParams, 'limit', static::ITEM_PER_PAGE);
        $role = Arr::get($searchParams, 'role', '');
        $keyword = Arr::get($searchParams, 'keyword', '');

        if (!empty($role)) {
            $userQuery->whereHas('roles', function($q) use ($role) { $q->where('name', $role); });
        }   

        if (!empty($keyword)) {
            $userQuery->where('name', 'LIKE', '%' . $keyword . '%');
            $userQuery->where('email', 'LIKE', '%' . $keyword . '%');
        }

        return UserResource::collection($userQuery->paginate($limit));

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            array_merge(
                $this->getValidationRules(),
                [
                    'password' => ['required', 'min:6'],
                    'confirmPassword' => 'same:password',
                ]
            )
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 403);
        } else {
            $params = $request->all();
            $user = User::create([
                'name' => $params['name'],
                'email' => $params['email'],
                'password' => Hash::make($params['password']),
            ]);
            $role = Role::findByName($params['role']);
            $user->syncRoles($role);

            return new UserResource($user);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  User $user
     * @return UserResource|\Illuminate\Http\JsonResponse
     */
    public function show(User $user)
    {
        return new UserResource($user);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param User    $user
     * @return UserResource|\Illuminate\Http\JsonResponse
     */
    public function update(Request $request, User $user)
    {
    	
    	if($request->has('nickName') && $request->get('nickName')!=''){
          $user->nickname = $request->get('nickName');
    	}

        if($request->has('is_notifications_option_active')){
          $user->is_notifications_option_active = $request->get('is_notifications_option_active') == true?'yes':'no';
        }

        if($request->has('is_notifications_option_active')){
          $user->is_marketing_option_active = $request->get('is_marketing_option_active') == true?'yes':'no';
        }

    	 if($request->has('image') && $request->get('image')!='') {
    	 	 $validator = Validator::make($request->all(), $this->imageUploaderValidation());
    	 	if ($validator->fails()) {
             return response()->json(['errors' => $validator->errors()], 403);
          } else {
			  $path1 = public_path('uploads');
				if (!File::exists($path1)) {
				  File::makeDirectory($path1, 0777, true);
				}

				$path2 = public_path('uploads/profile');
				if (!File::exists($path2)) {
				  File::makeDirectory($path2, 0777, true);
				}
			    $avatar = $user->id.'_'.time().".png";
        $path = public_path().'/uploads/profile/' . $avatar;
        if($user->$avatar != 'user.png') {
          $oldpath = public_path().'/uploads/profile/' . $user->$avatar;
          Image::make(file_get_contents($request->image))->save($path);   
                $user->avatar = $avatar;
            if (File::exists($oldpath)) {
              unlink($oldpath);
            }
        }
				

            }
    	 }
    	 if($request->has('new') && $request->has('re')){
    	 	$validator = Validator::make($request->all(), $this->passwordResetValidationRule());
    	 	if ($validator->fails()) {
             return response()->json(['errors' => $validator->errors()], 403);
           } else {
           	 $user->password = Hash::make(trim($request->get('re')));
           	 $user->save();
           	 //	$request->user()->tokens()->delete();
           }
    	 }else{
    	 	$user->save();
    	 }
        
        return new UserResource($user);
        die;

        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()
            && $currentUser->id !== $user->id
            && !$currentUser->hasPermission(\App\Lms\Acl::PERMISSION_USER_MANAGE)
        ) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $validator = Validator::make($request->all(), $this->getValidationRules(false));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 403);
        } else {
            $email = $request->get('email');
            $found = User::where('email', $email)->first();
            if ($found && $found->id !== $user->id) {
                return response()->json(['error' => 'Email has been taken'], 403);
            }

            $user->name = $request->get('name');
            $user->email = $email;
            $user->save();
            return new UserResource($user);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param User    $user
     * @return UserResource|\Illuminate\Http\JsonResponse
     */
    public function updatePermissions(Request $request, User $user)
    {
        if ($user === null) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($user->isAdmin()) {
            return response()->json(['error' => 'Admin can not be modified'], 403);
        }

        $permissionIds = $request->get('permissions', []);
        $rolePermissionIds = array_map(
            function($permission) {
                return $permission['id'];
            },

            $user->getPermissionsViaRoles()->toArray()
        );

        $newPermissionIds = array_diff($permissionIds, $rolePermissionIds);
        $permissions = Permission::allowed()->whereIn('id', $newPermissionIds)->get();
        $user->syncPermissions($permissions);
        return new UserResource($user);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  User $user
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {
        if ($user->isAdmin()) {
            response()->json(['error' => 'Ehhh! Can not delete admin user'], 403);
        }

        try {
            $user->delete();
        } catch (\Exception $ex) {
            response()->json(['error' => $ex->getMessage()], 403);
        }

        return response()->json(null, 204);
    }

    /**
     * Get permissions from role
     *
     * @param User $user
     * @return array|\Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function permissions(User $user)
    {
        try {
            return new JsonResponse([
                'user' => PermissionResource::collection($user->getDirectPermissions()),
                'role' => PermissionResource::collection($user->getPermissionsViaRoles()),
            ]);
        } catch (\Exception $ex) {
            response()->json(['error' => $ex->getMessage()], 403);
        }
    }

    /**
     * @param bool $isNew
     * @return array
     */
    private function getValidationRules($isNew = true)
    {
        return [
            'name' => 'required',
            'email' => $isNew ? 'required|email|unique:users' : 'required|email',
            'roles' => [
                'required',
                'array'
            ],
        ];
    }

    private function passwordResetValidationRule()
    {
        return [
            'new' => 'required|min:6',
            're' => 'same:new',
        ];
    }

    private function imageUploaderValidation()
    {
        return [
            'image' => 'required',
        ];
    }

    public function AllUsers(Request $request){
        $searchParams = $request->all();
        $userQuery = User::query();
        $limit = Arr::get($searchParams, 'limit', static::ITEM_PER_PAGE);
        $role = Arr::get($searchParams, 'role', '');
        $keyword = Arr::get($searchParams, 'keyword', '');

        if (!empty($role)) {
            $userQuery->whereHas('roles', function($q) use ($role) { $q->where('name', $role); });
        }   

        if (!empty($keyword)) {
            $userQuery->where('name', 'LIKE', '%' . $keyword . '%');
            $userQuery->where('email', 'LIKE', '%' . $keyword . '%');
        }

        return UserResource::collection($userQuery->paginate($limit));
    }

    public function MakeUserAdmin(Request $request){

       $Getpermission = PermissionGroup::where('id',1)->first();
       $Getpermission = explode(",",$Getpermission['permission_ids']);
       if($request[0] == "no"){
        $user_id = $request[1];
        $userData = User::where('id',$user_id)->first();
        $getCurrentPrmission = explode(",",$userData['access_permissions']);

        $newPermissions = array_merge($Getpermission,$getCurrentPrmission);
        $newPermissions = array_unique($newPermissions);
        $updateuser = User::find($user_id);
        $updateuser->is_mark_admin= 'yes';
        $updateuser->access_permissions= implode(',',$newPermissions);
        if($updateuser->save()){
              return response()->json(new JsonResponse(['status' => 'admin']));
            }
       }
       else{
            $user_id = $request[1];
            $userData = User::where('id',$user_id)->first();
            $getCurrentPrmission = explode(",",$userData['access_permissions']);

            $newPermissions = array_diff($getCurrentPrmission,$Getpermission);
            // $newPermissions = array_unique($newPermissions);
            $updateuser = User::find($user_id);
            $updateuser->is_mark_admin= 'no';
            $updateuser->access_permissions= implode(',',$newPermissions);
            if($updateuser->save()){
              return response()->json(new JsonResponse(['status' => 'user']));
            }
       }
    }

    public function signupuser(Request $request)
    {
        $Getpermission = PermissionGroup::where('id',1)->first();

        $validator = Validator::make($request->all(), [
        'first_name' => 'required|string|max:255',
        'last_name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users',
        'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails())
        {
            return response(['errors'=>$validator->errors()->all()], 422);
        }
        
        $user = new User();
        $user->email = $request->email;
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->password = Hash::make($request['password']);
        $user->access_permissions = $Getpermission['permission_ids'];
        $user->is_mark_admin = 'no';
        $user->is_marketing_option_active = 'yes';
        $user->is_notifications_option_active = 'yes';
        $user->role_id = 3;
 
        if($user->save()){

            return response()->json(new JsonResponse(['status' => 'success']));
        }
        else{
             return response()->json(new JsonResponse(['status' => 'fail']));
        }

    }


}
