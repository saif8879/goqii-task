-- DEVELOPMENT DATA ONLY. Never load this into a production database.
--
-- Every account below shares the password "Password123". They exist so the app
-- can be explored immediately, including the admin and non-admin views. A
-- production deployment loads db/init only and creates its first admin with
-- scripts/create-admin.php.

INSERT INTO users (id, name, email, password_hash, role)
VALUES (1, 'Saif Idrisi', 'saif@example.com',
        '$2y$10$wHSOEc.ttpP0AQLut4Wcr.yagyFIYpOlvlhp916ehEYtwD8DvWali', 'admin'),
       (2, 'Priya Sharma', 'priya@example.com',
        '$2y$10$wHSOEc.ttpP0AQLut4Wcr.yagyFIYpOlvlhp916ehEYtwD8DvWali', 'user'),
       (3, 'Rahul Verma', 'rahul@example.com',
        '$2y$10$wHSOEc.ttpP0AQLut4Wcr.yagyFIYpOlvlhp916ehEYtwD8DvWali', 'user')
ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role);

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
        'low', '2026-09-28', 3),
       ('Review error responses', 'Make sure validation failures return 422 with per-field messages.', 'todo', 'high',
        '2026-09-24', 3),
       ('Clean up unused imports', NULL, 'todo', 'low', NULL, 1);
