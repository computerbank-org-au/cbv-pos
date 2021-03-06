<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Customer class
 */

class Customer extends Person
{
    /*
    Determines if a given person_id is a customer
     */
    public function exists($person_id)
    {
        $this->db->from('customers');
        $this->db->join('people', 'people.person_id = customers.person_id');
        $this->db->where('customers.person_id', $person_id);

        return ($this->db->get()->num_rows() == 1);
    }

    /*
    Checks if concession id exists
     */
    public function check_conc_id_exists($conc_id, $person_id = '')
    {
        $this->db->from('customers');
        $this->db->where('conc_id', $conc_id);

        if (!empty($person_id)) {
            $this->db->where('person_id !=', $person_id);
        }

        return ($this->db->get()->num_rows() == 1);
    }

    /*
    Gets total of rows
     */
    public function get_total_rows()
    {
        $this->db->from('customers');
        $this->db->where('deleted', 0);

        return $this->db->count_all_results();
    }

    /*
    Returns all the customers
     */
    public function get_all($rows = 0, $limit_from = 0)
    {
        $this->db->from('customers');
        $this->db->join('people', 'customers.person_id = people.person_id');
        $this->db->where('deleted', 0);
        $this->db->order_by('last_name', 'asc');

        if ($rows > 0) {
            $this->db->limit($rows, $limit_from);
        }

        return $this->db->get();
    }

    /*
    Gets information about a particular customer
     */
    public function get_info($customer_id)
    {
        $this->db->from('customers');
        $this->db->join('people', 'people.person_id = customers.person_id');
        $this->db->where('customers.person_id', $customer_id);
        $query = $this->db->get();

        if ($query->num_rows() == 1) {
            return $query->row();
        } else {
            //Get empty base parent object, as $customer_id is NOT a customer
            $person_obj = parent::get_info(-1);

            //Get all the fields from customer table
            //append those fields to base parent object, we we have a complete empty object
            foreach ($this->db->list_fields('customers') as $field) {
                $person_obj->$field = '';
            }

            return $person_obj;
        }
    }

    /*
    Gets stats about a particular customer
     */
    public function get_stats($customer_id)
    {
        // create a temporary table to contain all the sum and average of items
        $this->db->query(
            'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->db->dbprefix('sales_items_temp') .
            ' (INDEX(sale_id))
			(
				SELECT
					sales.sale_id AS sale_id,
					SUM(sales_items.discount_amount) AS total_discount,
					SUM(sales_items.quantity_purchased) AS quantity
				FROM ' . $this->db->dbprefix('sales') . ' AS sales
				INNER JOIN ' . $this->db->dbprefix('sales_items') . ' AS sales_items
					ON sales_items.sale_id = sales.sale_id
				WHERE sales.customer_id = ' . $this->db->escape($customer_id) . '
				GROUP BY sale_id
			)'
        );

        $totals_decimals = totals_decimals();
        $quantity_decimals = quantity_decimals();

        $this->db->select('
						SUM(sales_payments.payment_amount) AS total,
						MIN(sales_payments.payment_amount) AS min,
						MAX(sales_payments.payment_amount) AS max,
						AVG(sales_payments.payment_amount) AS average,
						' . "
						SUM(sales_items_temp.total_discount) AS total_discount,
						ROUND(SUM(sales_items_temp.quantity), $quantity_decimals) AS quantity
						");
        $this->db->from('sales');
        $this->db->join('sales_payments AS sales_payments', 'sales.sale_id = sales_payments.sale_id');
        $this->db->join('sales_items_temp AS sales_items_temp', 'sales.sale_id = sales_items_temp.sale_id');
        $this->db->where('sales.customer_id', $customer_id);
        $this->db->where('sales.sale_status', COMPLETED);
        $this->db->group_by('sales.customer_id');

        $stat = $this->db->get()->row();

        // drop the temporary table to contain memory consumption as it's no longer required
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->db->dbprefix('sales_items_temp'));

        return $stat;
    }

    /*
    Gets information about multiple customers
     */
    public function get_multiple_info($customer_ids)
    {
        $this->db->from('customers');
        $this->db->join('people', 'people.person_id = customers.person_id');
        $this->db->where_in('customers.person_id', $customer_ids);
        $this->db->order_by('last_name', 'asc');

        return $this->db->get();
    }

    /*
    Checks if customer email exists
     */
    public function check_email_exists($email, $customer_id = '')
    {
        // if the email is empty return like it is not existing
        if (empty($email)) {
            return false;
        }

        $this->db->from('customers');
        $this->db->join('people', 'people.person_id = customers.person_id');
        $this->db->where('people.email', $email);
        $this->db->where('customers.deleted', 0);

        if (!empty($customer_id)) {
            $this->db->where('customers.person_id !=', $customer_id);
        }

        return ($this->db->get()->num_rows() >= 1);
    }

    /*
    Inserts or updates a customer
     */
    public function save_customer(&$person_data, &$customer_data, $customer_id = false)
    {
        $success = false;

        //Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->trans_start();

        if (parent::save($person_data, $customer_id)) {
            if (!$customer_id || !$this->exists($customer_id)) {
                $customer_data['person_id'] = $person_data['person_id'];
                $success = $this->db->insert('customers', $customer_data);
            } else {
                $this->db->where('person_id', $customer_id);
                $success = $this->db->update('customers', $customer_data);
            }
        }

        $this->db->trans_complete();

        $success &= $this->db->trans_status();

        return $success;
    }

    /*
    Updates reward points value
     */
    public function update_reward_points_value($customer_id, $value)
    {
        $this->db->where('person_id', $customer_id);
        $this->db->update('customers', array('points' => $value));
    }

    /*
    Deletes one customer
     */
    public function delete($customer_id)
    {
        $this->db->where('person_id', $customer_id);

        return $this->db->update('customers', array('deleted' => 1));
    }

    /*
    Deletes a list of customers
     */
    public function delete_list($customer_ids)
    {
        $this->db->where_in('person_id', $customer_ids);

        return $this->db->update('customers', array('deleted' => 1));
    }

    /*
    Get search suggestions to find customers
     */
    public function get_search_suggestions($search, $unique = true, $limit = 25)
    {
        $suggestions = array();

        $this->db->from('customers');
        $this->db->join('people', 'customers.person_id = people.person_id');
        $this->db->group_start();
        $this->db->like('first_name', $search);
        $this->db->or_like('last_name', $search);
        $this->db->or_like('CONCAT(first_name, " ", last_name)', $search);
        if ($unique) {
            $this->db->or_like('email', $search);
            $this->db->or_like('phone_number', $search);
            $this->db->or_like('company_name', $search);
        }
        $this->db->group_end();
        $this->db->where('deleted', 0);
        $this->db->order_by('last_name', 'asc');
        foreach ($this->db->get()->result() as $row) {
            $suggestions[] = array('value' => $row->person_id, 'label' => $row->first_name . ' ' . $row->last_name . (!empty($row->company_name) ? ' [' . $row->company_name . ']' : '') . (!empty($row->phone_number) ? ' [' . $row->phone_number . ']' : ''));
        }

        if (!$unique) {
            $this->db->from('customers');
            $this->db->join('people', 'customers.person_id = people.person_id');
            $this->db->where('deleted', 0);
            $this->db->like('email', $search);
            $this->db->order_by('email', 'asc');
            foreach ($this->db->get()->result() as $row) {
                $suggestions[] = array('value' => $row->person_id, 'label' => $row->email);
            }

            $this->db->from('customers');
            $this->db->join('people', 'customers.person_id = people.person_id');
            $this->db->where('deleted', 0);
            $this->db->like('phone_number', $search);
            $this->db->order_by('phone_number', 'asc');
            foreach ($this->db->get()->result() as $row) {
                $suggestions[] = array('value' => $row->person_id, 'label' => $row->phone_number);
            }

            $this->db->from('customers');
            $this->db->join('people', 'customers.person_id = people.person_id');
            $this->db->where('deleted', 0);
            $this->db->like('conc_id', $search);
            $this->db->order_by('conc_id', 'asc');
            foreach ($this->db->get()->result() as $row) {
                $suggestions[] = array('value' => $row->person_id, 'label' => $row->conc_id);
            }
            $this->db->from('customers');
            $this->db->join('people', 'customers.person_id = people.person_id');
            $this->db->where('deleted', 0);
            $this->db->like('company_name', $search);
            $this->db->order_by('company_name', 'asc');
            foreach ($this->db->get()->result() as $row) {
                $suggestions[] = array('value' => $row->person_id, 'label' => $row->company_name);
            }
        }

        //only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return $suggestions;
    }

    /*
    Gets rows
     */
    public function get_found_rows($search)
    {
        return $this->search($search, 0, 0, 'last_name', 'asc', true);
    }

    /*
    Performs a search on customers
     */
    public function search($search, $rows = 0, $limit_from = 0, $sort = 'last_name', $order = 'asc', $count_only = false)
    {
        // get_found_rows case
        if ($count_only == true) {
            $this->db->select('COUNT(customers.person_id) as count');
        }

        $this->db->from('customers AS customers');
        $this->db->join('people', 'customers.person_id = people.person_id');
        $this->db->group_start();
        $this->db->like('first_name', $search);
        $this->db->or_like('last_name', $search);
        $this->db->or_like('email', $search);
        $this->db->or_like('phone_number', $search);
        $this->db->or_like('conc_id', $search);
        $this->db->or_like('company_name', $search);
        $this->db->or_like('CONCAT(first_name, " ", last_name)', $search);
        $this->db->group_end();
        $this->db->where('deleted', 0);

        // get_found_rows case
        if ($count_only == true) {
            return $this->db->get()->row()->count;
        }

        $this->db->order_by($sort, $order);

        if ($rows > 0) {
            $this->db->limit($rows, $limit_from);
        }

        return $this->db->get();
    }

    public function lookup_cus_info($conc_id)
    {

        // select our data from the database
        $this->db->select("*");
        $this->db->from('customers');
        $this->db->join('people', 'customers.person_id = people.person_id');
        $this->db->where('conc_id =', $conc_id);

        // pass as the function result
        $query = $this->db->get();
        return $query->result();
    }

    public function lookup_cus_sales($conc_id)
    {

        // select our data from the database
        $this->db->select("*");
        $this->db->from('sales');
        $this->db->join('customers', 'sales.customer_id = customers.person_id');
        $this->db->join('sales_items', 'sales.sale_id = sales_items.sale_id');
        $this->db->join('items', 'sales_items.item_id = items.item_id');
        $this->db->join('people', 'customers.person_id = people.person_id');
        $this->db->where('conc_id =', $conc_id);

        // pass as the function result
        $query = $this->db->get();
        return $query->result();
    }
}
