-- Set test config settings
REPLACE INTO `core_config_data` (`path`, `value`, `scope`, `scope_id`) VALUES

    -- Allow for longer admin sessions
    ('admin/security/session_cookie_lifetime', 86400, 'default', 0),

    -- Disable robots.txt
    ('design/head/default_robots', 0, 'default', 0),

    -- Enable logs
    ('dev/log/active', 1, 'default', 0),

    /* Allow longer sessions */
    ('admin/security/session_cookie_lifetime', 86400, 'default', 0),
    ('web/cookie/cookie_lifetime', 86400, 'default', 0),

    -- Disable Google Analytics and Tag Manager
    ('google/analytics/active', 0, 'default', 0),
    ('google/tagmanager/active', 0, 'default', 0);

-- Create dev Magento admin user
REPLACE INTO `admin_user` (`firstname`, `lastname`, `email`, `username`, `password`, `created`, `is_active`) VALUES
    ('Robofirm', 'Dev', 'dev@localhost.com', 'dev', (SELECT MD5('password1')), NOW(), 1);
REPLACE INTO `admin_role` (`parent_id`, `tree_level`, `sort_order`, `role_type`, `user_id`, `role_name`) VALUES
    (1, 2, 0, 'U', (SELECT last_insert_id()), 'Robofirm Dev');
