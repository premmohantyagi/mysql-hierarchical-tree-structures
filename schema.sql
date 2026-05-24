-- =============================================================================
--  Hierarchical Tree Structures in MySQL
--
--  Implements two hierarchical models:
--    1. Adjacency List   (department      — parent_id pointer)
--    2. Nested Set       (department_ns   — lft / rgt boundary numbers)
--
--  Engine : InnoDB  (required for foreign keys + transactional inserts)
--  Charset: utf8mb4 (full Unicode, incl. emoji / multi-byte names)
-- =============================================================================

-- -----------------------------------------------------------------------------
--  Database
-- -----------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS hierarchical_data
    CHARACTER SET utf8mb4
    COLLATE      utf8mb4_unicode_ci;

USE hierarchical_data;

-- Clean slate (safe re-run). Drop child tables before parents.
DROP TABLE IF EXISTS employee;
DROP TABLE IF EXISTS department;
DROP TABLE IF EXISTS department_ns;


-- =============================================================================
--  MODEL 1 — Adjacency List
--  Each row stores its immediate parent via parent_id.
--  Simple writes; deep reads require recursive CTE or multiple self-joins.
-- =============================================================================
CREATE TABLE department (
    id         INT          NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50)  NOT NULL,
    parent_id  INT          DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Speeds up "find children of X" lookups (the most common query).
    KEY idx_department_parent_id (parent_id),

    -- Self-referencing FK. SET NULL on parent delete promotes orphans to root
    -- rather than cascading the whole subtree away by accident.
    CONSTRAINT fk_department_parent
        FOREIGN KEY (parent_id) REFERENCES department (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  MODEL 2 — Nested Set
--  Each row encodes its position in the tree as a (lft, rgt) range.
--  A node N is a descendant of node A iff  A.lft < N.lft AND N.rgt < A.rgt.
--  Excellent for read-heavy trees; writes require shifting boundary numbers.
-- =============================================================================
CREATE TABLE department_ns (
    id         INT         NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50) NOT NULL,
    lft        INT         NOT NULL,   -- "left"  — reserved word, hence lft
    rgt        INT         NOT NULL,   -- "right" — reserved word, hence rgt
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Boundary numbers must be unique within the tree and lft < rgt always.
    UNIQUE KEY uq_department_ns_lft (lft),
    UNIQUE KEY uq_department_ns_rgt (rgt),

    -- Composite index supports the BETWEEN range queries that drive every
    -- ancestor/descendant lookup in the article.
    KEY idx_department_ns_lft_rgt (lft, rgt),

    -- Sanity check: lft must always be strictly less than rgt.
    -- (CHECK constraints are enforced from MySQL 8.0.16+.)
    CONSTRAINT chk_department_ns_bounds CHECK (lft < rgt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  Shared: employee table for headcount aggregates.
--  Pointed at the adjacency-list department table by default — switch the FK
--  target to department_ns(id) if you prefer to drive aggregates off the
--  nested-set model.
-- =============================================================================
CREATE TABLE employee (
    id            INT         NOT NULL AUTO_INCREMENT,
    name          VARCHAR(50) NOT NULL,
    department_id INT         NOT NULL,
    created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_employee_department_id (department_id),

    CONSTRAINT fk_employee_department
        FOREIGN KEY (department_id) REFERENCES department (id)
        ON DELETE RESTRICT          -- don't silently lose employees
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  Seed data — example org chart
--
--      Company
--      ├── Engineering
--      │   ├── Backend
--      │   │   └── API Team
--      │   └── Frontend
--      └── Operations
--          ├── HR
--          └── Finance
--              └── Payroll
-- =============================================================================

-- --- Adjacency list ----------------------------------------------------------
INSERT INTO department (id, name, parent_id) VALUES
    (1, 'Company',     NULL),
    (2, 'Engineering', 1),
    (3, 'Backend',     2),
    (4, 'API Team',    3),
    (5, 'Frontend',    2),
    (6, 'Operations',  1),
    (7, 'HR',          6),
    (8, 'Finance',     6),
    (9, 'Payroll',     8);

-- --- Nested set --------------------------------------------------------------
INSERT INTO department_ns (id, name, lft, rgt) VALUES
    (1, 'Company',      1, 18),
    (2, 'Engineering',  2,  9),
    (3, 'Backend',      3,  6),
    (4, 'API Team',     4,  5),
    (5, 'Frontend',     7,  8),
    (6, 'Operations',  10, 17),
    (7, 'HR',          11, 12),
    (8, 'Finance',     13, 16),
    (9, 'Payroll',     14, 15);

-- --- Employees ---------------------------------------------------------------
INSERT INTO employee (name, department_id) VALUES
    ('Alice',   4),  -- API Team
    ('Bob',     4),  -- API Team
    ('Charlie', 5),  -- Frontend
    ('Diana',   7),  -- HR
    ('Edward',  9),  -- Payroll
    ('Fiona',   9);  -- Payroll


-- =============================================================================
--  Quick verification queries (uncomment to run after import)
-- =============================================================================
-- -- 1. Adjacency list: list every department with its parent name.
-- SELECT c.id, c.name AS department, p.name AS parent
-- FROM department c
-- LEFT JOIN department p ON p.id = c.parent_id
-- ORDER BY c.id;
--
-- -- 2. Adjacency list with MySQL 8 recursive CTE — full tree, any depth.
-- WITH RECURSIVE org_tree AS (
--     SELECT id, name, parent_id, 0 AS depth
--     FROM department WHERE parent_id IS NULL
--   UNION ALL
--     SELECT d.id, d.name, d.parent_id, ot.depth + 1
--     FROM department d
--     JOIN org_tree ot ON d.parent_id = ot.id
-- )
-- SELECT CONCAT(REPEAT('  ', depth), name) AS tree FROM org_tree ORDER BY id;
--
-- -- 3. Nested set: breadcrumb path to Payroll.
-- SELECT parent.name
-- FROM department_ns AS node, department_ns AS parent
-- WHERE node.lft BETWEEN parent.lft AND parent.rgt
--   AND node.name = 'Payroll'
-- ORDER BY parent.lft;
--
-- -- 4. Nested set: headcount per department (rolls up to ancestors).
-- SELECT parent.name, COUNT(e.id) AS headcount
-- FROM department_ns AS node, department_ns AS parent, employee AS e
-- WHERE node.lft BETWEEN parent.lft AND parent.rgt
--   AND node.id = e.department_id
-- GROUP BY parent.id, parent.name
-- ORDER BY parent.lft;
