<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Support\Collection;
use Livewire\Component;

class Logs extends Component
{
    public ?string $type = null;
    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb $resource;
    public Collection $servers;
    public Collection $containers;
    public $container = [];
    public $parameters;
    public $query;
    public $status;
    public $serviceSubType;

    public function loadContainers($server_id)
    {
        try {
            $server = $this->servers->firstWhere('id', $server_id);
            if ($server->isSwarm()) {
                $containers = collect([
                    [
                        'Names' => $this->resource->uuid . '_' . $this->resource->uuid,
                    ]
                ]);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true);
            }
            $server->containers = $containers;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
    public function mount()
    {
        $this->containers = collect();
        $this->servers = collect();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (data_get($this->parameters, 'application_uuid')) {
            $this->type = 'application';
            $this->resource = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail();
            $this->status = $this->resource->status;
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            foreach ($this->resource->additional_servers as $server) {
                if ($server->isFunctional()) {
                    $this->servers = $this->servers->push($server);
                }
            }
        } else if (data_get($this->parameters, 'database_uuid')) {
            $this->type = 'database';
            $resource = StandalonePostgresql::where('uuid', $this->parameters['database_uuid'])->first();
            if (is_null($resource)) {
                $resource = StandaloneRedis::where('uuid', $this->parameters['database_uuid'])->first();
                if (is_null($resource)) {
                    $resource = StandaloneMongodb::where('uuid', $this->parameters['database_uuid'])->first();
                    if (is_null($resource)) {
                        $resource = StandaloneMysql::where('uuid', $this->parameters['database_uuid'])->first();
                        if (is_null($resource)) {
                            $resource = StandaloneMariadb::where('uuid', $this->parameters['database_uuid'])->first();
                            if (is_null($resource)) {
                                abort(404);
                            }
                        }
                    }
                }
            }
            $this->resource = $resource;
            $this->status = $this->resource->status;
            if ($this->resource->destination->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->destination->server);
            }
            $this->container = $this->resource->uuid;
            $this->containers->push($this->container);
        } else if (data_get($this->parameters, 'service_uuid')) {
            $this->type = 'service';
            $this->resource = Service::where('uuid', $this->parameters['service_uuid'])->firstOrFail();
            $this->resource->applications()->get()->each(function ($application) {
                $this->containers->push(data_get($application, 'name') . '-' . data_get($this->resource, 'uuid'));
            });
            $this->resource->databases()->get()->each(function ($database) {
                $this->containers->push(data_get($database, 'name') . '-' . data_get($this->resource, 'uuid'));
            });

            if ($this->resource->server->isFunctional()) {
                $this->servers = $this->servers->push($this->resource->server);
            }
        }
    }

    public function render()
    {
        return view('livewire.project.shared.logs');
    }
}
