<?php


namespace App\Http\Controllers;
use App\Models\BacklogProject;
use App\Models\Backlogs;
use Illuminate\Http\Request;
use App\Models\Vote;
use Yajra\DataTables\Facades\DataTables;

class VotingController extends Controller
{
    
    public function index()
    {
        $projects = BacklogProject::where('status', '1')->get();
        return view('admin.voting', compact('projects'));
    }
    
    public function getVoting(Request $request)
    {
        if ($request->ajax()) {
            $records = Vote::with(['Projects', 'Users']);
            $filters = json_decode($request->filters, true) ?? [];
            $records = $this->recordFilter($records, $filters);
            
            return DataTables::of($records)
                ->addColumn('voting_id', function($row) {
                    return $row->id;
                })
                ->addColumn('project_id', function($row) {
                    return $row->projects ? $row->projects->id : 'N/A';
                })
                ->addColumn('user_name', function($row) {
                    return $row->users ? $row->users->first_name . ' ' . $row->users->last_name : 'N/A';
                })
                ->addColumn('vote_type', function($row) {
                    return $row->vote_type == 'up' ? '👍' : '👎';
                })
                ->addColumn('comment', function($row) {
                    return $row->comment ? $row->comment : 'N/A';
                })
                ->addColumn('created_at', function($row) {
                    return $row->created_at ? $row->created_at->format('d M Y') : 'N/A';
                })
                ->addColumn('actions', function($row) {
                    return '<div class="dropdown">
                                <button class="btn btn-secondary btn-sm dropdown-toggle"
                                    type="button" data-bs-toggle="dropdown"
                                    aria-expanded="false">
                                    Actions
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item delete-vote" data-id="'.$row->id.'">Delete</a>
                                    </li>                       
                                </ul>
                            </div>';
                })
                ->rawColumns(['actions'])
                ->with([
                    'total_projects' => BacklogProject::count(),
                    'complete_project' => BacklogProject::where('status', '1')->count(),
                    'total_backlogs' => Backlogs::count(),
                    'complete_backlog' => Backlogs::where('status_id', '5')->count(),
                ])
                ->make(true);
        }
        
        // Fallback for non-ajax requests (keeping original structure)
        $data = array();
        $records = Vote::with(['Projects', 'Users']);
        $filters = json_decode($request->filters, true) ?? [];
        $records = $this->recordFilter($records, $filters);
        $data['records'] = $records->orderBy('created_at','DESC')->get();
        $data['total_backlogs'] = Backlogs::count();
        $data['complete_backlog'] = Backlogs::where('status_id','5')->count();
        $data['total_projects'] = BacklogProject::count();
        $data['complete_project'] = BacklogProject::where('status','1')->count();
        return response()->json(['status' => 200, 'data' => $data]);
    }
    
    public function recordFilter($records, $filters)
    {
        $hasFilters = false; // Track if any filters are applied
        // Apply category filters
        if (!empty($filters['projectFilter'])) {
            $records->where('project_id', $filters['projectFilter']);
            $hasFilters = true;
        }
        // Apply date range filters
        if (!empty($filters['date_range']['start']) && !empty($filters['date_range']['end'])) {
            $startDate = $filters['date_range']['start'];
            $endDate = $filters['date_range']['end'] . ' 23:59:59';
            $records->whereBetween('created_at', [$startDate, $endDate]);
            $hasFilters = true;
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $records->where(function ($query) use ($search) {
                $query->where('votes.comment', 'LIKE', "%$search%")
                    ->orWhereHas('Users', function ($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%$search%")
                            ->orWhere('last_name', 'LIKE', "%$search%");
                    })
                    ->orWhereHas('Projects', function ($q) use ($search) {
                        $q->where('title', 'LIKE', "%$search%");
                    });
            });
            $hasFilters = true;
        }
        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            if ($filters['sort']['column'] == 'project_title') {
                $records->join('backlog_projects', 'votes.project_id', '=', 'backlog_projects.id')
                    ->select('votes.*')
                    ->orderBy('backlog_projects.title', $filters['sort']['order']);
            } else if ($filters['sort']['column'] == 'user_name') {
                $records->join('users', 'votes.user_id', '=', 'users.id')
                    ->select('votes.*')
                    ->orderBy('users.first_name', $filters['sort']['order']);
            } else {
                $records->orderBy('vote_type', $filters['sort']['order']);
            }
            $hasFilters = true;
        }
        if (!empty($filters['showDeletedBacklogs'])) {
            $records->onlyTrashed();
        }
        // Apply default ordering if no filters were applied
        if (!$hasFilters) {
            $records->orderBy('created_at', 'DESC');
        }
        return $records;
    }
    
    public function deleteVote(Request $request)
    {
        $id = $request->id;
        $record = Vote::find($id);
        $record->delete();
        return response()->json(['status' => 200, 'message' => 'Record Delete Successfully']);
    }
}