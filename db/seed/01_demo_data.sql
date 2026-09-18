-- DEVELOPMENT DATA ONLY. Never load this into a production database.
--
-- Each account has its own password, listed in the README. They exist so the app
-- can be explored immediately, including the admin and non-admin views. A
-- production deployment loads db/init only and creates its first admin with
-- scripts/create-admin.php.

INSERT INTO users (id, name, email, password_hash, role)
VALUES (1, 'Saif Idrisi', 'saifidrisi77@gmail.com',
        '$2y$10$JP46WhxmusFW6u0zV6EZv.9.so4.hAna.Wh/1ukficaLjRkcZb5qm', 'admin'),
       (2, 'Rahul Verma', 'rahul@wrap2earn.com',
        '$2y$10$32yN0ZZGgK/cIZv8kHLbUeBYWIbd7qCKW13J3bK51.oGmoJ.mP1jO', 'user')
ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email),
                        password_hash = VALUES(password_hash), role = VALUES(role);

INSERT INTO tasks (title, description, status, priority, due_date, user_id)
VALUES ('Set up project repository', 'Initialise the repo, add a README and the base folder structure.', 'done',
        'medium', '2026-09-10', 1),
       ('Design the tasks table', 'Decide on columns, indexes and the foreign key to users.', 'done', 'high',
        '2026-09-12', 1),
       ('Build the list endpoint', 'Support filtering by status plus limit/offset pagination.', 'in-progress', 'high',
        '2026-09-20', 1),
       ('Wire up the React task list', 'Fetch from the API and handle the loading and error states.', 'in-progress',
        'medium', '2026-09-22', 2),
       ('Add client-side validation', 'Mirror the server rules so users get feedback before submitting.', 'todo',
        'medium', '2026-09-25', 2),
       ('Write API documentation', 'Document every route, the request body and the status codes it returns.', 'todo',
        'low', '2026-09-28', 2),
       ('Review error responses', 'Make sure validation failures return 422 with per-field messages.', 'todo', 'high',
        '2026-09-24', 2),
       ('Clean up unused imports', NULL, 'todo', 'low', NULL, 1);
