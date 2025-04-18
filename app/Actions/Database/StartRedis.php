<?php

namespace App\Actions\Database;

use App\Helpers\SslHelper;
use App\Models\SslCertificate;
use App\Models\StandaloneRedis;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartRedis
{
    use AsAction;

    public StandaloneRedis $database;

    public array $commands = [];

    public string $configuration_dir;

    private ?SslCertificate $ssl_certificate = null;

    public function handle(StandaloneRedis $database)
    {
        $this->database = $database;

        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir().'/'.$container_name;

        $this->commands = [
            "echo 'Starting database.'",
            "echo 'Creating directories.'",
            "mkdir -p $this->configuration_dir",
            "echo 'Directories created successfully.'",
        ];

        if (! $this->database->enable_ssl) {
            $this->commands[] = "rm -rf $this->configuration_dir/ssl";
            $this->database->sslCertificates()->delete();
            $this->database->fileStorages()
                ->where('resource_type', $this->database->getMorphClass())
                ->where('resource_id', $this->database->id)
                ->get()
                ->filter(function ($storage) {
                    return in_array($storage->mount_path, [
                        '/etc/redis/certs/server.crt',
                        '/etc/redis/certs/server.key',
                    ]);
                })
                ->each(function ($storage) {
                    $storage->delete();
                });
        } else {
            $this->commands[] = "echo 'Setting up SSL for this database.'";
            $this->commands[] = "mkdir -p $this->configuration_dir/ssl";

            $server = $this->database->destination->server;
            $caCert = SslCertificate::where('server_id', $server->id)->where('is_ca_certificate', true)->first();

            if (! $caCert) {
                $server->generateCaCertificate();
                $caCert = SslCertificate::where('server_id', $server->id)->where('is_ca_certificate', true)->first();
            }

            if (! $caCert) {
                $this->dispatch('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');

                return;
            }

            $this->ssl_certificate = $this->database->sslCertificates()->first();

            if (! $this->ssl_certificate) {
                $this->commands[] = "echo 'No SSL certificate found, generating new SSL certificate for this database.'";
                $this->ssl_certificate = SslHelper::generateSslCertificate(
                    commonName: $this->database->uuid,
                    resourceType: $this->database->getMorphClass(),
                    resourceId: $this->database->id,
                    serverId: $server->id,
                    caCert: $caCert->ssl_certificate,
                    caKey: $caCert->ssl_private_key,
                    configurationDir: $this->configuration_dir,
                    mountPath: '/etc/redis/certs',
                );
            }
        }

        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->database->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();
        $this->add_custom_redis();

        $startCommand = $this->buildStartCommand();

        $docker_compose = [
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'command' => $startCommand,
                    'container_name' => $container_name,
                    'environment' => $environment_variables,
                    'restart' => RESTART_MODE,
                    'networks' => [
                        $this->database->destination->network,
                    ],
                    'labels' => defaultDatabaseLabels($this->database)->toArray(),
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            'redis-cli',
                            'ping',
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 10,
                        'start_period' => '5s',
                    ],
                    'mem_limit' => $this->database->limits_memory,
                    'memswap_limit' => $this->database->limits_memory_swap,
                    'mem_swappiness' => $this->database->limits_memory_swappiness,
                    'mem_reservation' => $this->database->limits_memory_reservation,
                    'cpus' => (float) $this->database->limits_cpus,
                    'cpu_shares' => $this->database->limits_cpu_shares,
                ],
            ],
            'networks' => [
                $this->database->destination->network => [
                    'external' => true,
                    'name' => $this->database->destination->network,
                    'attachable' => true,
                ],
            ],
        ];

        if (! is_null($this->database->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->database->limits_cpuset);
        }

        if ($this->database->destination->server->isLogDrainEnabled() && $this->database->isLogDrainEnabled()) {
            $docker_compose['services'][$container_name]['logging'] = generate_fluentd_configuration();
        }

        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        }

        $docker_compose['services'][$container_name]['volumes'] ??= [];

        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                $persistent_storages
            );
        }

        if (count($persistent_file_volumes) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'],
                $persistent_file_volumes->map(function ($item) {
                    return "$item->fs_path:$item->mount_path";
                })->toArray()
            );
        }

        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }

        if ($this->database->enable_ssl) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'] ?? [],
                [
                    [
                        'type' => 'bind',
                        'source' => '/data/coolify/ssl/coolify-ca.crt',
                        'target' => '/etc/redis/certs/coolify-ca.crt',
                        'read_only' => true,
                    ],
                ]
            );
        }

        if (! is_null($this->database->redis_conf) || ! empty($this->database->redis_conf)) {
            $docker_compose['services'][$container_name]['volumes'][] = [
                'type' => 'bind',
                'source' => $this->configuration_dir.'/redis.conf',
                'target' => '/usr/local/etc/redis/redis.conf',
                'read_only' => true,
            ];
        }

        // Add custom docker run options
        $docker_run_options = convertDockerRunToCompose($this->database->custom_docker_run_options);
        $docker_compose = generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $this->database->destination->network);

        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d | tee $this->configuration_dir/docker-compose.yml > /dev/null";
        $readme = generate_readme_file($this->database->name, now());
        $this->commands[] = "echo '{$readme}' > $this->configuration_dir/README.md";
        $this->commands[] = "echo 'Pulling {$database->image} image.'";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml pull";
        if ($this->database->enable_ssl) {
            $this->commands[] = "chown -R 999:999 $this->configuration_dir/ssl/server.key $this->configuration_dir/ssl/server.crt";
        }
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml up -d";
        $this->commands[] = "echo 'Database started.'";

        return remote_process($this->commands, $database->destination->server, callEventOnFinish: 'DatabaseStatusChanged');
    }

    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path !== '' && $persistentStorage->host_path !== null) {
                $local_persistent_volumes[] = $persistentStorage->host_path.':'.$persistentStorage->mount_path;
            } else {
                $volume_name = $persistentStorage->name;
                $local_persistent_volumes[] = $volume_name.':'.$persistentStorage->mount_path;
            }
        }

        return $local_persistent_volumes;
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        $local_persistent_volumes_names = [];
        foreach ($this->database->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;
            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }

        return $local_persistent_volumes_names;
    }

    private function generate_environment_variables()
    {
        $environment_variables = collect();

        foreach ($this->database->runtime_environment_variables as $env) {
            if ($env->is_shared) {
                $environment_variables->push("$env->key=$env->real_value");

                if ($env->key === 'REDIS_PASSWORD') {
                    $this->database->update(['redis_password' => $env->real_value]);
                }

                if ($env->key === 'REDIS_USERNAME') {
                    $this->database->update(['redis_username' => $env->real_value]);
                }
            } else {
                if ($env->key === 'REDIS_PASSWORD') {
                    $env->update(['value' => $this->database->redis_password]);
                } elseif ($env->key === 'REDIS_USERNAME') {
                    $env->update(['value' => $this->database->redis_username]);
                }
                $environment_variables->push("$env->key=$env->real_value");
            }
        }

        add_coolify_default_environment_variables($this->database, $environment_variables, $environment_variables);

        return $environment_variables->all();
    }

    private function buildStartCommand(): string
    {
        $hasRedisConf = ! is_null($this->database->redis_conf) && ! empty($this->database->redis_conf);
        $redisConfPath = '/usr/local/etc/redis/redis.conf';

        if ($hasRedisConf) {
            $confContent = $this->database->redis_conf;
            $hasRequirePass = str_contains($confContent, 'requirepass');

            if ($hasRequirePass) {
                $command = "redis-server $redisConfPath";
            } else {
                $command = "redis-server $redisConfPath --requirepass {$this->database->redis_password}";
            }
        } else {
            $command = "redis-server --requirepass {$this->database->redis_password} --appendonly yes";
        }

        if ($this->database->enable_ssl) {
            $sslArgs = [
                '--tls-port 6380',
                '--tls-cert-file /etc/redis/certs/server.crt',
                '--tls-key-file /etc/redis/certs/server.key',
                '--tls-ca-cert-file /etc/redis/certs/coolify-ca.crt',
                '--tls-auth-clients optional',
            ];
        }

        if (! empty($sslArgs)) {
            $command .= ' '.implode(' ', $sslArgs);
        }

        return $command;
    }

    private function add_custom_redis()
    {
        if (is_null($this->database->redis_conf) || empty($this->database->redis_conf)) {
            return;
        }
        $filename = 'redis.conf';
        Storage::disk('local')->put("tmp/redis.conf_{$this->database->uuid}", $this->database->redis_conf);
        $path = Storage::path("tmp/redis.conf_{$this->database->uuid}");
        instant_scp($path, "{$this->configuration_dir}/{$filename}", $this->database->destination->server);
        Storage::disk('local')->delete("tmp/redis.conf_{$this->database->uuid}");
    }
}
