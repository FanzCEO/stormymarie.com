import mysql from 'mysql2/promise';
import dotenv from 'dotenv';

dotenv.config();

// Database configuration from environment variables
const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  port: parseInt(process.env.DB_PORT) || 3306,
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'stormymarie',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  enableKeepAlive: true,
  keepAliveInitialDelay: 0
};

let pool;
let connected = false;

try {
  pool = mysql.createPool(dbConfig);
  pool.getConnection()
    .then(conn => {
      console.log('Database connected successfully to MariaDB');
      connected = true;
      conn.release();
    })
    .catch(err => {
      console.warn('Database not available (running in static mode):', err.message);
    });
} catch (err) {
  console.warn('Database not configured (running in static mode)');
}

// Helper class with graceful fallback when DB is unavailable
const db = {
  pool,

  async execute(sql, params = []) {
    if (!pool) return { affectedRows: 0 };
    const [result] = await pool.execute(sql, params);
    return result;
  },

  async query(sql, params = []) {
    if (!pool) return [];
    const [rows] = await pool.execute(sql, params);
    return rows;
  },

  async get(sql, params = []) {
    if (!pool) return null;
    const [rows] = await pool.execute(sql, params);
    return rows[0] || null;
  },

  async all(sql, params = []) {
    if (!pool) return [];
    const [rows] = await pool.execute(sql, params);
    return rows;
  },

  async run(sql, params = []) {
    if (!pool) return { lastInsertRowid: 0, changes: 0 };
    const [result] = await pool.execute(sql, params);
    return {
      lastInsertRowid: result.insertId,
      changes: result.affectedRows
    };
  },

  prepare(sql) {
    return {
      run: async (...params) => {
        if (!pool) return { lastInsertRowid: 0, changes: 0 };
        const [result] = await pool.execute(sql, params);
        return {
          lastInsertRowid: result.insertId,
          changes: result.affectedRows
        };
      },
      get: async (...params) => {
        if (!pool) return null;
        const [rows] = await pool.execute(sql, params);
        return rows[0] || null;
      },
      all: async (...params) => {
        if (!pool) return [];
        const [rows] = await pool.execute(sql, params);
        return rows;
      }
    };
  },

  async transaction(callback) {
    if (!pool) return null;
    const connection = await pool.getConnection();
    await connection.beginTransaction();
    try {
      const result = await callback(connection);
      await connection.commit();
      return result;
    } catch (error) {
      await connection.rollback();
      throw error;
    } finally {
      connection.release();
    }
  }
};

export default db;
