<?php

namespace esportspools\SphinxSearch;

use Sphinx\SphinxClient;

class SphinxSearch
{
    protected $_connection;
    protected $_index_name;
    protected $_search_string;
    protected $_config;
    protected $_total_count;
    protected $_time;
    protected $_eager_loads;
    protected $_raw_mysql_connection;

    public function __construct()
    {
        $host    = \Config::get('sphinxsearch.host');
        $port    = \Config::get('sphinxsearch.port');
        $timeout = \Config::get('sphinxsearch.timeout');

        $this->_connection = new SphinxClient();

        $this->_connection->setServer($host, $port);
        $this->_connection->setConnectTimeout($timeout);
        $this->_connection->setMatchMode(SphinxClient::SPH_MATCH_ANY);
        $this->_connection->setSortMode(SphinxClient::SPH_SORT_RELEVANCE);

        if (extension_loaded('mysqli') && \Config::get('sphinxsearch.mysql_server'))
        {
            $this->_raw_mysql_connection = mysqli_connect(\Config::get('sphinxsearch.mysql_server.host'), '', '', '', \Config::get('sphinxsearch.mysql_server.port'));
        }

        $this->_config = \Config::get('sphinxsearch.indexes');

        reset($this->_config);

        $this->_index_name  = isset($this->_config['name']) ? implode(',', $this->_config['name']) : key($this->_config);
        $this->_eager_loads = [];
    }


    /**
     * @param $docs
     * @param $index_name
     * @param $query
     * @param array $extra , in this format: array('option_name' => option_value, 'limit' => 100, ...)
     * @return array
     */
    public function getSnippetsQL($docs, $index_name, $query, $extra = []): array
    {
        if (is_array($docs) === FALSE)
        {
            $docs = [$docs];
        }

        foreach ($docs as &$doc)
        {
            $doc = "'" . mysqli_real_escape_string($this->_raw_mysql_connection, strip_tags($doc)) . "'";
        }

        $extra_ql = '';

        if ($extra)
        {
            foreach ($extra as $key => $value)
            {
                $extra_ql[] = $value . ' AS ' . $key;
            }

            $extra_ql = implode(',', $extra_ql);

            if ($extra_ql)
            {
                $extra_ql = ',' . $extra_ql;
            }
        }

        $query  = 'CALL SNIPPETS((' . implode(',', $docs) . "),'" . $index_name . "','" . mysqli_real_escape_string($this->_raw_mysql_connection, $query) . "' " . $extra_ql . ')';
        $result = mysqli_query($this->_raw_mysql_connection, $query);
        $reply  = [];

        if ($result)
        {
            while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC))
            {
                $reply[] = $row['snippet'];
            }
        }

        return $reply;
    }

    public function search($string, $index_name = NULL)
    {
        $this->_search_string = $string;

        if (NULL !== $index_name)
        {
            // if index name contains , or ' ', multiple index search
            if (strpos($index_name, ' ') || strpos($index_name, ','))
            {
                if (!isset($this->_config['mapping']))
                {
                    $this->_config['mapping'] = FALSE;
                }
            }

            $this->_index_name = $index_name;
        }

        $this->_connection->resetFilters();
        $this->_connection->resetGroupBy();

        return $this;
    }


    public function setFieldWeights($weights)
    {
        $this->_connection->setFieldWeights($weights);

        return $this;
    }


    public function setMatchMode($mode)
    {
        $this->_connection->setMatchMode($mode);

        return $this;
    }


    public function setRankingMode($mode)
    {
        $this->_connection->setRankingMode($mode);

        return $this;
    }


    public function setSortMode($mode, $sortby = NULL)
    {
        $this->_connection->setSortMode($mode, $sortby);

        return $this;
    }


    public function setFilterFloatRange($attribute, $min, $max, $exclude = FALSE)
    {
        $this->_connection->setFilterFloatRange($attribute, $min, $max, $exclude);

        return $this;
    }


    public function setGeoAnchor($attrlat, $attrlong, $lat = NULL, $long = NULL)
    {
        $this->_connection->setGeoAnchor($attrlat, $attrlong, $lat, $long);

        return $this;
    }


    public function setGroupBy($attribute, $func, $groupsort = '@group desc')
    {
        $this->_connection->setGroupBy($attribute, $func, $groupsort);

        return $this;
    }


    public function setSelect($select)
    {
        $this->_connection->setSelect($select);

        return $this;
    }


    public function limit($limit, $offset = 0, $max_matches = 1000, $cutoff = 1000)
    {
        $this->_connection->setLimits($offset, $limit, $max_matches, $cutoff);

        return $this;
    }


    public function filter($attribute, $values, $exclude = FALSE)
    {
        if (is_array($values))
        {
            $val = [];

            foreach ($values as $v)
            {
                $val[] = (int)$v;
            }

        }
        else
        {
            $val = [(int)$values];
        }

        $this->_connection->setFilter($attribute, $val, $exclude);

        return $this;
    }


    public function range($attribute, $min, $max, $exclude = FALSE)
    {
        $this->_connection->setFilterRange($attribute, $min, $max, $exclude);

        return $this;
    }


    public function query()
    {
        return $this->_connection->query($this->_search_string, $this->_index_name);
    }


    public function excerpt($content, $opts = [])
    {
        return $this->_connection->buildExcerpts([$content], $this->_index_name, $this->_search_string, $opts);
    }


    public function excerpts($contents, $opts = [])
    {
        return $this->_connection->buildExcerpts($contents, $this->_index_name, $this->_search_string, $opts);
    }


    public function get($respect_sort_order = FALSE)
    {
        $this->_total_count = 0;
        $result             = $this->_connection->query($this->_search_string, $this->_index_name);

        // Process results.
        if ($result)
        {
            // Get total count of existing results.
            $this->_total_count = (int)$result['total_found'];

            // Get time taken for search.
            $this->_time = $result['time'];

            if ($result['total'] && isset($result['matches']))
            {
                // Get results' id's and query the database.
                $matchids   = array_keys($result['matches']);
                $idString   = implode(',', $matchids);
                $config     = $this->_config['mapping'] ?? $this->_config[$this->_index_name];
                $primaryKey = $config['primaryKey'] ?? 'id';

                if ($config)
                {
                    if (isset($config['modelname']))
                    {
                        if ($this->_eager_loads)
                        {
                            $result = call_user_func($config['modelname'] . '::whereIn', $config['column'], $matchids)
                                ->orderByRaw(\DB::raw("FIELD($primaryKey, $idString)"))->with($this->_eager_loads)
                                ->get();
                        }
                        else
                        {
                            $result = call_user_func($config['modelname'] . '::whereIn', $config['column'], $matchids)
                                ->orderByRaw(\DB::raw("FIELD($primaryKey, $idString)"))->get();
                        }
                    }
                    else
                    {
                        $result = \DB::table($config['table'])->whereIn($config['column'], $matchids)
                                     ->orderByRaw(\DB::raw("FIELD($primaryKey, $idString)"))->get();
                    }
                }
            }
            else
            {
                $result = [];
            }
        }

        if ($respect_sort_order && NULL !== $matchids)
        {
            $return_val = [];

            foreach ($matchids as $matchid)
            {
                $key = self::getResultKeyByID($matchid, $result);

                if (FALSE !== $key)
                {
                    $return_val[] = $result[$key];
                }
            }
            return $return_val;
        }

        // important: reset the array of eager loads prior to making next call
        $this->_eager_loads = [];

        return $result;
    }


    public function with()
    {
        // Allow multiple with-calls
        if (FALSE === isset($this->_eager_loads))
        {
            $this->_eager_loads = [];
        }

        foreach (func_get_args() as $a)
        {
            // Add closures as name=>function()
            if (is_array($a))
            {
                $this->_eager_loads = array_merge($this->_eager_loads, $a);
            }
            else
            {
                $this->_eager_loads[] = $a;
            }
        }

        return $this;
    }


    public function getTotalCount()
    {
        return $this->_total_count;
    }


    public function getTime()
    {
        return $this->_time;
    }


    public function getErrorMessage(): string
    {
        return $this->_connection->getLastError();
    }


    private function getResultKeyByID($id, $result)
    {
        if (count($result) > 0)
        {
            foreach ($result as $k => $result_item)
            {
                if ($result_item->id == $id)
                {
                    return $k;
                }
            }
        }

        return FALSE;
    }


    public function escapeStringQL($string): string
    {
        return $this->_connection->escapeString($string);
    }
}
