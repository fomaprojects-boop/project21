import mysql.connector

try:
    conn = mysql.connector.connect(
        host='localhost',
        user='app_chatmedb',
        password='chatme2025@',
        database='app_chatmedb'
    )
    cursor = conn.cursor()
    cursor.execute("DESCRIBE settings")
    columns = cursor.fetchall()
    print("Current settings table schema:")
    for col in columns:
        print(f"{col[0]} ({col[1]})")

    conn.close()
except mysql.connector.Error as err:
    print(f"Error: {err}")
except ImportError:
    print("mysql-connector-python not installed")
