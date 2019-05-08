<?php

namespace App\Service\Redis;

class RedisTracking
{
    /**
     * Track a stat
     */
    public static function track(string $constant, $value)
    {
        $tracking = Redis::Cache()->get('mb_tracking') ?: [];
        $tracking[$constant] = $value;
        
        Redis::Cache()->set("mb_tracking", $tracking, 3600 * 24);
    }
    
    /**
     * Increment a stat
     */
    public static function increment(string $constant, $value = 1)
    {
        $tracking = Redis::Cache()->get('mb_tracking') ?: [];
        $tracking[$constant] = isset($tracking[$constant]) ? $tracking[$constant] + 1 : 1;
        
        Redis::Cache()->set("mb_tracking", $tracking, 3600 * 24);
    }
    
    /**
     * Get all tracking stats
     */
    public static function get()
    {
        return  Redis::Cache()->get('mb_tracking_');
    }
    
    /**
     * Reset all tracking stats or a single one passed in
     */
    public static function reset()
    {
        Redis::Cache()->delete('mb_tracking');
    }
}
