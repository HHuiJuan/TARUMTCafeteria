const express = require('express');
const mysql = require('mysql2/promise');
const app = express();
app.use(express.json());

// Connect to your RDS database
const db = mysql.createPool({
  host: 'your-rds-endpoint',   // e.g. cafeteria.xxxxx.rds.amazonaws.com
  user: 'cafeadmin',
  password: 'Cafe-pass1234',
  database: 'cafeteria'
});

// Route: categories
app.get('/api/categories', async (req, res) => {
  try {
    const [rows] = await db.query('SELECT id, name, icon FROM category');
    res.json(rows);
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Failed to fetch categories' });
  }
});

// Route: menu items
app.get('/api/menu', async (req, res) => {
  try {
    const [rows] = await db.query('SELECT id, name, description, price, category_id, is_available FROM menuitem');
    res.json(rows);
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Failed to fetch menu' });
  }
});

// Route: orders
app.post('/api/orders', async (req, res) => {
  try {
    const order = req.body;
    await db.query('INSERT INTO orders SET ?', order);
    res.json({ success: true });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'Failed to save order' });
  }
});

app.listen(3000, () => console.log('API running on port 3000'));
