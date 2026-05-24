# Hierarchical Tree Structures in MySQL

## The Problem

A relational database stores data in flat tables  rows and columns. But real-world data is often *hierarchical*: a company has departments, departments have teams, teams have employees. File systems have folders inside folders. Comment threads have replies inside replies.

The challenge is that SQL tables have no built-in concept of "parent" or "child". We have to model that relationship ourselves. This article covers two ways to do it, using a company org chart as the running example.

---

## The Example: A Company Org Chart

Imagine a company structured like this:

```
Company
├── Engineering
│   ├── Backend
│   │   └── API Team
│   └── Frontend
├── Operations
│   ├── HR
│   └── Finance
│       └── Payroll
```

We want to store this in MySQL and be able to answer questions like:

- What are all the departments under Engineering?
- What is the full path from Payroll up to the root?
- How deeply nested is each department?

---

## Model 1: The Adjacency List

This is the most intuitive approach. Each row simply stores a `parent_id` pointing to its immediate parent.

```sql
CREATE TABLE department (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(50) NOT NULL,
    parent_id INT DEFAULT NULL
);

INSERT INTO department VALUES
    (1, 'Company',     NULL),
    (2, 'Engineering', 1),
    (3, 'Backend',     2),
    (4, 'API Team',    3),
    (5, 'Frontend',    2),
    (6, 'Operations',  1),
    (7, 'HR',          6),
    (8, 'Finance',     6),
    (9, 'Payroll',     8);
```

The table looks like this:

```
+----+-------------+-----------+
| id | name        | parent_id |
+----+-------------+-----------+
|  1 | Company     |      NULL |
|  2 | Engineering |         1 |
|  3 | Backend     |         2 |
|  4 | API Team    |         3 |
|  5 | Frontend    |         2 |
|  6 | Operations  |         1 |
|  7 | HR          |         6 |
|  8 | Finance     |         6 |
|  9 | Payroll     |         8 |
+----+-------------+-----------+
```

Each row knows only its direct parent  the `parent_id` column is a pointer. The diagram below shows what those pointers look like when drawn as arrows:

```
id | name        | parent_id
---+-------------+----------
 1 | Company     |  NULL ────── (root, no parent)
 2 | Engineering |    1  ──────────────────────────→ row 1 (Company)
 3 | Backend     |    2  ──────────────────────────→ row 2 (Engineering)
 4 | API Team    |    3  ──────────────────────────→ row 3 (Backend)
 5 | Frontend    |    2  ──────────────────────────→ row 2 (Engineering)
 6 | Operations  |    1  ──────────────────────────→ row 1 (Company)
 7 | HR          |    6  ──────────────────────────→ row 6 (Operations)
 8 | Finance     |    6  ──────────────────────────→ row 6 (Operations)
 9 | Payroll     |    8  ──────────────────────────→ row 8 (Finance)
```

> Finding Payroll's ancestors means following pointers: Payroll → Finance → Operations → Company. Each hop is a separate query or join.

---

### Retrieving the Full Tree

To display the full tree in SQL, you join the table to itself  once per level:

```sql
SELECT
    t1.name AS level1,
    t2.name AS level2,
    t3.name AS level3,
    t4.name AS level4
FROM department AS t1
LEFT JOIN department AS t2 ON t2.parent_id = t1.id
LEFT JOIN department AS t3 ON t3.parent_id = t2.id
LEFT JOIN department AS t4 ON t4.parent_id = t3.id
WHERE t1.name = 'Company';
```

```
+----------+-------------+---------+----------+
| level1   | level2      | level3  | level4   |
+----------+-------------+---------+----------+
| Company  | Engineering | Backend | API Team |
| Company  | Engineering | Frontend| NULL     |
| Company  | Operations  | HR      | NULL     |
| Company  | Operations  | Finance | Payroll  |
+----------+-------------+---------+----------+
```

The hard limit here: **you need one self-join per level of depth**. A tree 6 levels deep requires 6 joins, and the query must be rewritten every time the tree grows.

---

### Finding Leaf Nodes

Leaf nodes are departments with no children. Find them with a `LEFT JOIN` looking for rows that never appear as a `parent_id`:

```sql
SELECT d1.name
FROM department AS d1
LEFT JOIN department AS d2 ON d1.id = d2.parent_id
WHERE d2.id IS NULL;
```

```
+----------+
| name     |
+----------+
| API Team |
| Frontend |
| HR       |
| Payroll  |
+----------+
```

---

### The Core Problem with Adjacency Lists

The adjacency list is natural for inserts and single parent-child lookups. But for reading deep paths  "what is the full breadcrumb from Payroll to Company?"  or rolling up counts across an entire subtree, you either need MySQL 8 recursive CTEs or application-level loops. In plain SQL it doesn't scale with depth.

---

## Model 2: The Nested Set

The Nested Set model stores **two numbers per node  a left (`lft`) and a right (`rgt`)**  that encode the entire tree structure without any parent pointers.

### The Core Idea: Nesting as Numbers

Picture walking around the *outside* of the tree, as if tracing its outline with a pencil. You start on the left of the root and work your way right, going down into every subtree before coming back up.

- Every time you **enter** a node, you write down the next number as its `lft`.
- Every time you **leave** a node, you write down the next number as its `rgt`.

```
   Enter Company → lft = 1
     Enter Engineering → lft = 2
       Enter Backend → lft = 3
         Enter API Team → lft = 4
         Leave  API Team → rgt = 5
       Leave  Backend → rgt = 6
       Enter Frontend → lft = 7
       Leave  Frontend → rgt = 8
     Leave  Engineering → rgt = 9
     Enter Operations → lft = 10
       Enter HR → lft = 11
       Leave  HR → rgt = 12
       Enter Finance → lft = 13
         Enter Payroll → lft = 14
         Leave  Payroll → rgt = 15
       Leave  Finance → rgt = 16
     Leave  Operations → rgt = 17
   Leave  Company → rgt = 18
```

This traversal is called the **modified preorder tree traversal algorithm**.

> `lft` and `rgt` are used instead of `left` and `right` because those are reserved words in MySQL.

---

### Visualising the Hierarchy as Nested Containers

The easiest way to see how this works is to picture each department as a physical container, with child departments nested inside their parent:

```
┌─────────────────────────────────────────────────────────────────┐
│  COMPANY                                                  1  18 │
│                                                                 │
│  ┌──────────────────────────┐  ┌──────────────────────────────┐ │
│  │  ENGINEERING        2  9 │  │  OPERATIONS            10  17│ │
│  │                          │  │                              │ │
│  │  ┌──────────┐ ┌────────┐ │  │  ┌──────┐   ┌─────────────┐  │ │
│  │  │ BACKEND  │ │FRONTEND│ │  │  │  HR  │   │   FINANCE   │  │ │
│  │  │   3  6   │ │  7  8  │ │  │  │11 12 │   │    13  16   │  │ │
│  │  │ ┌──────┐ │ └────────┘ │  │  └──────┘   │  ┌────────┐ │  │ │
│  │  │ │ API  │ │            │  │             │  │PAYROLL │ │  │ │
│  │  │ │ TEAM │ │            │  │             │  │ 14  15 │ │  │ │
│  │  │ │ 4  5 │ │            │  │             │  └────────┘ │  │ │
│  │  │ └──────┘ │            │  │             └─────────────┘  │ │
│  │  └──────────┘            │  └──────────────────────────────┘ │
│  └──────────────────────────┘                                   │
└─────────────────────────────────────────────────────────────────┘
                           numbers shown are lft  rgt
```

Notice how the hierarchy is still intact  parent containers envelop their children. And critically: **a department is an ancestor of another if the descendant's `lft` falls inside the ancestor's `lft..rgt` range**.

---

### The Numbered Tree

Here is the same structure as a tree, with every node labelled on the left with its `lft` and on the right with its `rgt`:

```
              1 Company 18
             /              \
      2 Engineering 9    10 Operations 17
         /        \           /        \
   3 Backend 6  7 Frontend 8  11 HR 12  13 Finance 16
      |                                     |
   4 API Team 5                         14 Payroll 15
```

The numbers on the left edge of each node = `lft` (entering).
The numbers on the right edge = `rgt` (leaving).

---

### Setting Up the Table

```sql
CREATE TABLE department_ns (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    lft  INT NOT NULL,
    rgt  INT NOT NULL
);

INSERT INTO department_ns VALUES
    (1, 'Company',      1, 18),
    (2, 'Engineering',  2,  9),
    (3, 'Backend',      3,  6),
    (4, 'API Team',     4,  5),
    (5, 'Frontend',     7,  8),
    (6, 'Operations',  10, 17),
    (7, 'HR',          11, 12),
    (8, 'Finance',     13, 16),
    (9, 'Payroll',     14, 15);
```

```
+----+-------------+-----+-----+
| id | name        | lft | rgt |
+----+-------------+-----+-----+
|  1 | Company     |   1 |  18 |
|  2 | Engineering |   2 |   9 |
|  3 | Backend     |   3 |   6 |
|  4 | API Team    |   4 |   5 |
|  5 | Frontend    |   7 |   8 |
|  6 | Operations  |  10 |  17 |
|  7 | HR          |  11 |  12 |
|  8 | Finance     |  13 |  16 |
|  9 | Payroll     |  14 |  15 |
+----+-------------+-----+-----+
```

---

### The Key Insight

**A node is an ancestor of another if the descendant's `lft` falls between the ancestor's `lft` and `rgt`.**

```
Finance  lft=13, rgt=16
Payroll  lft=14  ← 14 is between 13 and 16  ✓  Payroll is inside Finance

HR       lft=11  ← 11 is NOT between 13 and 16  ✗  HR is not inside Finance
```

This single rule powers almost every nested set query below.

---

### Retrieving the Full Tree

```sql
SELECT node.name
FROM department_ns AS node,
     department_ns AS parent
WHERE node.lft BETWEEN parent.lft AND parent.rgt
  AND parent.name = 'Company'
ORDER BY node.lft;
```

```
+-------------+
| name        |
+-------------+
| Company     |
| Engineering |
| Backend     |
| API Team    |
| Frontend    |
| Operations  |
| HR          |
| Finance     |
| Payroll     |
+-------------+
```

Unlike the adjacency list, **this query works regardless of depth**  no extra joins needed as the tree grows.

---

### Finding Leaf Nodes

A leaf has no children, so nothing was visited between entering and leaving it  meaning `rgt` is always exactly `lft + 1`:

```sql
SELECT name
FROM department_ns
WHERE rgt = lft + 1;
```

```
+----------+
| name     |
+----------+
| API Team |
| Frontend |
| HR       |
| Payroll  |
+----------+
```

```
Leaf check:
  API Team  lft=4, rgt=5  → rgt = lft + 1  ✓ leaf
  Backend   lft=3, rgt=6  → rgt ≠ lft + 1  ✗ has children
```

---

### Retrieving the Path to a Node (Breadcrumbs)

To get the breadcrumb trail for any node  all nodes that *contain* it  flip the logic: find every row whose `lft..rgt` range contains the target's `lft`:

```sql
SELECT parent.name
FROM department_ns AS node,
     department_ns AS parent
WHERE node.lft BETWEEN parent.lft AND parent.rgt
  AND node.name = 'Payroll'
ORDER BY parent.lft;
```

```
+------------+
| name       |
+------------+
| Company    |
| Operations |
| Finance    |
| Payroll    |
+------------+
```

How it works for Payroll (lft=14):

```
Row         lft  rgt   Is 14 between lft and rgt?
----------  ---  ---   ---------------------------
Company       1   18   YES  (1 ≤ 14 ≤ 18)   → ancestor
Engineering   2    9   NO   (14 > 9)
Backend       3    6   NO
API Team      4    5   NO
Frontend      7    8   NO
Operations   10   17   YES  (10 ≤ 14 ≤ 17)  → ancestor
HR           11   12   NO
Finance      13   16   YES  (13 ≤ 14 ≤ 16)  → ancestor
Payroll      14   15   YES  (itself)
```

No recursive queries, no fixed number of joins  just a range check.

---

### Finding the Depth of Each Node

Count how many rows contain a node (i.e., how many ancestors it has), and subtract 1:

```sql
SELECT node.name, (COUNT(parent.name) - 1) AS depth
FROM department_ns AS node,
     department_ns AS parent
WHERE node.lft BETWEEN parent.lft AND parent.rgt
GROUP BY node.id, node.name
ORDER BY node.lft;
```

```
+-------------+-------+
| name        | depth |
+-------------+-------+
| Company     |     0 |
| Engineering |     1 |
| Backend     |     2 |
| API Team    |     3 |
| Frontend    |     2 |
| Operations  |     1 |
| HR          |     2 |
| Finance     |     2 |
| Payroll     |     3 |
+-------------+-------+
```

> Always `GROUP BY node.id` rather than `node.name`  names may not be unique.

Use `CONCAT` and `REPEAT` to visually indent the result:

```sql
SELECT CONCAT(REPEAT('  ', COUNT(parent.name) - 1), node.name) AS name
FROM department_ns AS node,
     department_ns AS parent
WHERE node.lft BETWEEN parent.lft AND parent.rgt
GROUP BY node.id, node.name
ORDER BY node.lft;
```

```
+------------------+
| name             |
+------------------+
| Company          |
|   Engineering    |
|     Backend      |
|       API Team   |
|     Frontend     |
|   Operations     |
|     HR           |
|     Finance      |
|       Payroll    |
+------------------+
```

---

### Depth Within a Sub-Tree

To show depth relative to a chosen starting point (so the starting node shows as depth 0), add a sub-query to calculate its absolute depth first, then subtract:

```sql
SELECT node.name, (COUNT(parent.name) - (sub_tree.depth + 1)) AS depth
FROM department_ns AS node,
     department_ns AS parent,
     department_ns AS sub_parent,
     (
         SELECT node.name, (COUNT(parent.name) - 1) AS depth
         FROM department_ns AS node,
              department_ns AS parent
         WHERE node.lft BETWEEN parent.lft AND parent.rgt
           AND node.name = 'Operations'
         GROUP BY node.id, node.name
     ) AS sub_tree
WHERE node.lft BETWEEN parent.lft AND parent.rgt
  AND node.lft BETWEEN sub_parent.lft AND sub_parent.rgt
  AND sub_parent.name = sub_tree.name
GROUP BY node.id, node.name
ORDER BY node.lft;
```

```
+------------+-------+
| name       | depth |
+------------+-------+
| Operations |     0 |
| HR         |     1 |
| Finance    |     1 |
| Payroll    |     2 |
+------------+-------+
```

---

### Finding Only Immediate Children

Useful for navigation menus: show direct children of a node only, not the full subtree. Add `HAVING depth <= 1` to the sub-tree query above:

```sql
-- (same query as above for 'Operations', append:)
HAVING depth <= 1
ORDER BY node.lft;
```

```
+------------+-------+
| name       | depth |
+------------+-------+
| Operations |     0 |
| HR         |     1 |
| Finance    |     1 |
+------------+-------+
```

Change `<= 1` to `= 1` to exclude the parent itself and return only its direct children.

---

### Counting Records Per Node (Aggregates)

Say each department has employees and we want a headcount including all descendants. Add an `employee` table and join through the nested set:

```sql
CREATE TABLE employee (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(50),
    department_id INT NOT NULL
);

INSERT INTO employee (name, department_id) VALUES
    ('Alice',   4),  -- API Team
    ('Bob',     4),  -- API Team
    ('Charlie', 5),  -- Frontend
    ('Diana',   7),  -- HR
    ('Edward',  9),  -- Payroll
    ('Fiona',   9);  -- Payroll
```

```sql
SELECT parent.name, COUNT(employee.id) AS headcount
FROM department_ns AS node,
     department_ns AS parent,
     employee
WHERE node.lft BETWEEN parent.lft AND parent.rgt
  AND node.id = employee.department_id
GROUP BY parent.id, parent.name
ORDER BY parent.lft;
```

```
+-------------+-----------+
| name        | headcount |
+-------------+-----------+
| Company     |         6 |
| Engineering |         3 |
| Backend     |         2 |
| API Team    |         2 |
| Frontend    |         1 |
| Operations  |         3 |
| HR          |         1 |
| Finance     |         2 |
| Payroll     |         2 |
+-------------+-----------+
```

Parent departments automatically roll up headcounts from all descendants.

---

## Modifying the Tree

### Adding a New Node

Adding a node requires shifting every node to the right of the insertion point by **+2** (one `lft` and one `rgt` are consumed by the new node).

**Before**  inserting DevOps under Engineering, after Frontend (rgt=8):

```
lft                                                           rgt
 1 [─────────────────── Company ─────────────────────────────] 18
      2 [── Engineering ──] 9        10 [── Operations ──] 17
         3[Backend]6  7[Frontend]8      11[HR]12  13[Finance]16
            4[API]5                               14[Payroll]15
                             ↑ insert here (after rgt=8)
```

Steps:

```sql
LOCK TABLE department_ns WRITE;

SELECT @position := rgt FROM department_ns WHERE name = 'Frontend';  -- @position = 8

UPDATE department_ns SET rgt = rgt + 2 WHERE rgt > @position;  -- shift right boundary
UPDATE department_ns SET lft = lft + 2 WHERE lft > @position;  -- shift left boundary

INSERT INTO department_ns (name, lft, rgt)
VALUES ('DevOps', @position + 1, @position + 2);               -- lft=9, rgt=10

UNLOCK TABLES;
```

**After**  DevOps is in, Operations and everything to its right shifted +2:

```
lft                                                              rgt
 1 [──────────────────── Company ──────────────────────────────] 20
      2 [────── Engineering ──────] 11     12 [── Operations ──] 19
         3[Backend]6  7[Frontend]8  9[DevOps]10  13[HR]14  15[Finance]18
            4[API]5                                        16[Payroll]17
```

To add the first child under a leaf node, use `lft` instead of `rgt` as the insertion point:

```sql
LOCK TABLE department_ns WRITE;

SELECT @position := lft FROM department_ns WHERE name = 'HR';

UPDATE department_ns SET rgt = rgt + 2 WHERE rgt > @position;
UPDATE department_ns SET lft = lft + 2 WHERE lft > @position;

INSERT INTO department_ns (name, lft, rgt)
VALUES ('Recruitment', @position + 1, @position + 2);

UNLOCK TABLES;
```

---

### Deleting a Leaf Node

Deleting a leaf (or an entire subtree) removes the node(s) and closes the gap by shifting everything to the right by **−width**:

**Before**  deleting HR (lft=11, rgt=12, width=2):

```
10 [────────── Operations ──────────] 17
      11 [HR] 12    13 [── Finance ──] 16
      ↑ delete       14 [Payroll] 15
```

```sql
LOCK TABLE department_ns WRITE;

SELECT @lft := lft, @rgt := rgt, @width := rgt - lft + 1
FROM department_ns WHERE name = 'HR';      -- @lft=11, @rgt=12, @width=2

DELETE FROM department_ns WHERE lft BETWEEN @lft AND @rgt;

UPDATE department_ns SET rgt = rgt - @width WHERE rgt > @rgt;
UPDATE department_ns SET lft = lft - @width WHERE lft > @rgt;

UNLOCK TABLES;
```

**After**  gap closed, Finance and Payroll shifted left by 2:

```
10 [──────── Operations ────────] 15
      11 [── Finance ──] 14
           12 [Payroll] 13
```

This same pattern works for deleting an entire subtree  `DELETE … WHERE lft BETWEEN @lft AND @rgt` removes all descendants in one go, and `@width` accounts for the full span.

---

### Deleting a Parent but Keeping Its Children

To remove a parent node and promote its children one level up:

```sql
LOCK TABLE department_ns WRITE;

SELECT @lft := lft, @rgt := rgt
FROM department_ns WHERE name = 'Backend';

-- 1. Delete only the parent row
DELETE FROM department_ns WHERE lft = @lft;

-- 2. Children move up one level (close the gap the parent's lft left)
UPDATE department_ns
SET rgt = rgt - 1, lft = lft - 1
WHERE lft BETWEEN @lft AND @rgt;

-- 3. Everything to the right shifts down by 2 (parent consumed 2 numbers)
UPDATE department_ns SET rgt = rgt - 2 WHERE rgt > @rgt;
UPDATE department_ns SET lft = lft - 2 WHERE lft > @rgt;

UNLOCK TABLES;
```

```
Before:  2 [── Engineering ──] 9
              3 [Backend] 6
                4 [API Team] 5

After:   2 [── Engineering ──] 7
              3 [API Team] 4   ← promoted, renumbered
```

---

## Comparison: Which Model to Use?

| Concern                      | Adjacency List       | Nested Set            |
|------------------------------|----------------------|-----------------------|
| Easy to understand           | ✓ Yes                | Requires learning     |
| Easy inserts / moves         | ✓ Yes                | Requires recalculation|
| Arbitrary-depth tree reads   | Needs recursion      | ✓ Single query        |
| Breadcrumb / ancestor path   | Needs recursion      | ✓ Single query        |
| Aggregate counts per subtree | Complex              | ✓ Simple join         |
| Leaf node detection          | LEFT JOIN            | ✓ `rgt = lft + 1`    |
| Works with MySQL 8 CTEs      | ✓ Yes                | Both work             |
| Good for write-heavy trees   | ✓ Yes                | Use with care         |
| Good for read-heavy trees    | Manageable           | ✓ Excellent           |



---

## A Note on Modern MySQL

MySQL 8.0 added support for **recursive Common Table Expressions (CTEs)**, which makes adjacency list queries much cleaner at any depth:

```sql
WITH RECURSIVE org_tree AS (
    SELECT id, name, parent_id, 0 AS depth
    FROM department
    WHERE name = 'Company'

    UNION ALL

    SELECT d.id, d.name, d.parent_id, ot.depth + 1
    FROM department d
    JOIN org_tree ot ON d.parent_id = ot.id
)
SELECT * FROM org_tree ORDER BY depth, id;
```

This traverses the adjacency list to any depth without a fixed number of joins, largely resolving the model's main limitation.

The Nested Set remains faster for range-based aggregates and breadcrumbs on large, read-heavy trees  but recursive CTEs close the gap considerably for most everyday use cases.
