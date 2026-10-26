-- Atomic, bounded per-user presence. Only foreground/activity advance activity.
local now = tonumber(ARGV[1])
local device = ARGV[2]
local event = ARGV[3]
local idle = tonumber(ARGV[4])
local heartbeat = tonumber(ARGV[5])
local ttl = tonumber(ARGV[6])
local maximum = tonumber(ARGV[7])

local devices = redis.call('HGETALL', KEYS[1])
for i = 1, #devices, 2 do
    local state = cjson.decode(devices[i + 1])
    if state.heartbeatAt + heartbeat <= now then
        redis.call('HDEL', KEYS[1], devices[i])
        redis.call('ZREM', KEYS[2], devices[i])
    end
end
redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', now)

if event == 'background' then
    redis.call('HDEL', KEYS[1], device)
    redis.call('ZREM', KEYS[2], device)
else
    local stored = redis.call('HGET', KEYS[1], device)
    if not stored and event ~= 'foreground' then
        -- Late activity/heartbeat after background cannot reopen a device.
        return {1, redis.call('ZCOUNT', KEYS[2], '(' .. now, '+inf') > 0 and 1 or 0}
    end
    if not stored and redis.call('HLEN', KEYS[1]) >= maximum then
        return {2, redis.call('ZCOUNT', KEYS[2], '(' .. now, '+inf') > 0 and 1 or 0}
    end
    local state = stored and cjson.decode(stored) or {}
    if event == 'foreground' or event == 'activity' then
        state.activityAt = now
    end
    state.heartbeatAt = now
    redis.call('HSET', KEYS[1], device, cjson.encode(state))
    local expires = math.min(state.activityAt + idle, now + heartbeat)
    if expires > now then
        redis.call('ZADD', KEYS[2], expires, device)
    else
        redis.call('ZREM', KEYS[2], device)
    end
end
redis.call('EXPIRE', KEYS[1], ttl)
redis.call('EXPIRE', KEYS[2], ttl)
return {0, redis.call('ZCOUNT', KEYS[2], '(' .. now, '+inf') > 0 and 1 or 0}
